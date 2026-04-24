<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Tool: file_extract_text (MBS-10761 SPEZ §4.7.5).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent\tools\file;

use local_ai_manager\agent\execution_context;
use local_ai_manager\agent\itt_executor;
use local_ai_manager\agent\pdf_rasterizer;
use local_ai_manager\agent\tool_result;
use local_ai_manager\agent\tools\base_tool;
use local_ai_manager\local\agent\entity\file_extraction_cache_entry;

/**
 * Extract plain text from a file the user has uploaded to a draft area.
 *
 * Two-stage strategy:
 *   1. For text/* MIME types, the file content is read directly (UTF-8).
 *   2. For application/pdf, ghostscript's `txtwrite` device is tried first
 *      (embedded text). If the text density (chars / page) is below the
 *      configured threshold, or if the caller passed force_ocr=true, the
 *      pages are rasterised to PNG (capped at the configured page limit) and
 *      each page is sent through the image-to-text purpose (`aipurpose_itt`).
 *
 * Results are cached in {@see file_extraction_cache_entry}, keyed by the
 * sha256 of the raw file content plus the mechanism used. Cache TTL is
 * configurable via `local_ai_manager/file_extract_cache_ttl_days`.
 *
 * Read tool, but still requires approval because the content leaves the
 * user's context and is forwarded to an LLM in the ITT path. Not reversible
 * (no side effect beyond quota use).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class file_extract_text extends base_tool {

    /** Default: minimum chars-per-page below which we fall back to OCR. */
    private const DEFAULT_MIN_CHARS_PER_PAGE = 50;
    /** Default: maximum pages that may be rasterised + OCR'd per call. */
    private const DEFAULT_MAX_PAGES = 50;
    /** Default: cache TTL in days. */
    private const DEFAULT_CACHE_TTL_DAYS = 7;

    #[\Override]
    public function get_name(): string {
        return 'file_extract_text';
    }

    #[\Override]
    public function get_summary(): string {
        return get_string('tool_file_extract_text_summary', 'local_ai_manager');
    }

    #[\Override]
    public function get_description(): string {
        return <<<EOT
Use this tool when the user references a file they uploaded (PDF or plain
text) and asks the assistant to read, summarise, translate, or otherwise
process the file's content. Typical triggers: "Fasse die hochgeladene PDF
zusammen", "Übersetze diese Datei", "Was steht in der Datei 'hausaufgaben.pdf'?".

Behavior: Requires {draftitemid, filename}. Optional: force_ocr (default
false). For text/* MIME types the file content is read directly. For PDFs
the tool first tries to extract embedded text; if the density is too low or
force_ocr is true, pages are rasterised and each page is sent through the
image-to-text purpose. The result is cached by sha256 contenthash.

Do NOT use this tool for files that are not uploaded to a user draft area
(use the backing file-picker first), for non-PDF binary formats such as
images, videos, or office documents (the tool returns unsupported_mime_type),
or for files larger than the page limit allows — output is truncated in that
case and the `truncated` flag is set.

Returns: {text, pages, mechanism:'converter'|'itt', truncated}.

Examples:
  - "Fasse die PDF 'klausur.pdf' aus meinem Upload zusammen" ->
    file_extract_text({draftitemid:123, filename:"klausur.pdf"})
  - "Lies die PDF 'scan.pdf' per OCR" ->
    file_extract_text({draftitemid:123, filename:"scan.pdf", force_ocr:true})
EOT;
    }

    #[\Override]
    public function get_category(): string {
        return 'file';
    }

    #[\Override]
    public function get_keywords(): array {
        return ['file', 'pdf', 'ocr', 'extract', 'text', 'upload', 'document', 'datei', 'lesen'];
    }

    #[\Override]
    public function requires_approval(): bool {
        return true;
    }

    #[\Override]
    public function is_idempotent(): bool {
        return true;
    }

    #[\Override]
    public function is_reversible(): bool {
        return false;
    }

    #[\Override]
    public function supports_parallel(): bool {
        return false;
    }

    #[\Override]
    public function get_timeout_seconds(): int {
        return 180;
    }

    #[\Override]
    public function get_parameters_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'draftitemid' => [
                    'type' => 'integer',
                    'description' => 'Draft area item id that contains the uploaded file.',
                    'minimum' => 1,
                ],
                'filename' => [
                    'type' => 'string',
                    'description' => 'Filename inside the draft area (exact match, no paths).',
                    'minLength' => 1,
                    'maxLength' => 255,
                ],
                'force_ocr' => [
                    'type' => 'boolean',
                    'description' => 'Force ITT-based OCR even if embedded PDF text is available.',
                    'default' => false,
                ],
            ],
            'required' => ['draftitemid', 'filename'],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function get_result_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'text' => ['type' => 'string'],
                'pages' => ['type' => 'integer'],
                'mechanism' => ['type' => 'string', 'enum' => ['converter', 'itt']],
                'truncated' => ['type' => 'boolean'],
            ],
        ];
    }

    #[\Override]
    public function describe_for_user(array $args): string {
        return get_string('tool_file_extract_text_describe', 'local_ai_manager', (object) [
            'filename' => (string) ($args['filename'] ?? ''),
        ]);
    }

    #[\Override]
    protected function run(array $args, execution_context $ctx): tool_result {
        $draftitemid = (int) $args['draftitemid'];
        $filename = trim((string) $args['filename']);
        $forceocr = (bool) ($args['force_ocr'] ?? false);

        if ($filename === '') {
            return tool_result::failure('filename_missing',
                get_string('tool_file_extract_text_missing_filename', 'local_ai_manager'));
        }

        // Draft files live in the user's context, component=user, filearea=draft.
        $usercontext = \core\context\user::instance($ctx->user->id);
        $fs = get_file_storage();
        $file = $fs->get_file(
            $usercontext->id,
            'user',
            'draft',
            $draftitemid,
            '/',
            $filename
        );
        if (!$file || $file->is_directory()) {
            return tool_result::failure('file_not_found',
                get_string('tool_file_extract_text_not_found', 'local_ai_manager'));
        }

        $mimetype = (string) $file->get_mimetype();
        $supported = str_starts_with($mimetype, 'text/') || $mimetype === 'application/pdf';
        if (!$supported) {
            return tool_result::failure('unsupported_mime_type',
                get_string('tool_file_extract_text_unsupported', 'local_ai_manager', $mimetype));
        }

        $content = (string) $file->get_content();
        $sha = hash('sha256', $content);

        // Decide the mechanism up-front so we can cache separately.
        $mechanism = ($mimetype === 'application/pdf' && $forceocr)
            ? file_extraction_cache_entry::MECHANISM_ITT
            : file_extraction_cache_entry::MECHANISM_CONVERTER;

        // Cache lookup.
        $cached = $this->lookup_cache($sha, $mechanism, $ctx);
        if ($cached !== null) {
            return tool_result::success([
                'text' => (string) $cached->get('extracted_text'),
                'pages' => (int) ($cached->get('pages') ?? 0),
                'mechanism' => (string) $cached->get('mechanism'),
                'truncated' => (bool) $cached->get('truncated'),
            ], metrics: ['cache' => 'hit']);
        }

        // Actual extraction.
        if (str_starts_with($mimetype, 'text/')) {
            return $this->extract_text_file($content, $sha, $ctx);
        }
        // application/pdf from here on.
        return $this->extract_pdf($content, $sha, $forceocr, $ctx);
    }

    /**
     * Handle a plain-text file: decode, store, return.
     *
     * @param string $content Raw file bytes.
     * @param string $sha sha256 contenthash.
     * @param execution_context $ctx
     * @return tool_result
     */
    private function extract_text_file(string $content, string $sha, execution_context $ctx): tool_result {
        // Best-effort UTF-8 normalisation.
        if (!mb_check_encoding($content, 'UTF-8')) {
            $converted = @mb_convert_encoding($content, 'UTF-8', 'auto');
            if ($converted !== false) {
                $content = $converted;
            }
        }
        $this->write_cache($sha, file_extraction_cache_entry::MECHANISM_CONVERTER, $content, 1, false, $ctx);
        return tool_result::success([
            'text' => $content,
            'pages' => 1,
            'mechanism' => file_extraction_cache_entry::MECHANISM_CONVERTER,
            'truncated' => false,
        ], metrics: ['cache' => 'miss']);
    }

    /**
     * Handle a PDF: try ghostscript txtwrite first (unless force_ocr),
     * then fall back to rasterise + ITT OCR.
     *
     * @param string $content Raw file bytes.
     * @param string $sha sha256 contenthash.
     * @param bool $forceocr
     * @param execution_context $ctx
     * @return tool_result
     */
    private function extract_pdf(string $content, string $sha, bool $forceocr, execution_context $ctx): tool_result {
        $rasterizer = \core\di::get(pdf_rasterizer::class);

        // Write the PDF to a temp file ghostscript can read.
        $tmppdf = make_request_directory() . '/input.pdf';
        file_put_contents($tmppdf, $content);

        $pagecount = $rasterizer->get_page_count($tmppdf);
        if ($pagecount === 0) {
            $pagecount = 1;
        }

        $minchars = (int) (get_config('local_ai_manager', 'file_extract_min_chars_per_page') ?: self::DEFAULT_MIN_CHARS_PER_PAGE);
        $maxpages = (int) (get_config('local_ai_manager', 'file_extract_max_pages') ?: self::DEFAULT_MAX_PAGES);

        if (!$forceocr) {
            $text = $rasterizer->extract_text($tmppdf);
            $chars = mb_strlen(trim($text));
            $density = $pagecount > 0 ? ($chars / $pagecount) : 0;
            if ($chars > 0 && $density >= $minchars) {
                $this->write_cache(
                    $sha,
                    file_extraction_cache_entry::MECHANISM_CONVERTER,
                    $text,
                    $pagecount,
                    false,
                    $ctx
                );
                return tool_result::success([
                    'text' => $text,
                    'pages' => $pagecount,
                    'mechanism' => file_extraction_cache_entry::MECHANISM_CONVERTER,
                    'truncated' => false,
                ], metrics: ['cache' => 'miss', 'pdf_text_density' => (int) $density]);
            }
        }

        // ITT OCR fallback.
        $truncated = $pagecount > $maxpages;
        $pagestoread = min($pagecount, $maxpages);
        $pngs = $rasterizer->rasterise_pages($tmppdf, $pagestoread);
        if ($pngs === []) {
            return tool_result::failure('pdf_rasterise_failed',
                get_string('tool_file_extract_text_raster_failed', 'local_ai_manager'));
        }

        $executor = \core\di::get(itt_executor::class);
        $parts = [];
        $index = 0;
        foreach ($pngs as $pngpath) {
            $index++;
            $bytes = @file_get_contents($pngpath);
            if ($bytes === false || $bytes === '') {
                continue;
            }
            $pagetext = $executor->extract(base64_encode($bytes), $ctx->context->id);
            $parts[] = '--- Page ' . $index . ' ---' . "\n" . $pagetext;
        }
        $fulltext = implode("\n\n", $parts);

        $this->write_cache(
            $sha,
            file_extraction_cache_entry::MECHANISM_ITT,
            $fulltext,
            $pagestoread,
            $truncated,
            $ctx
        );
        return tool_result::success([
            'text' => $fulltext,
            'pages' => $pagestoread,
            'mechanism' => file_extraction_cache_entry::MECHANISM_ITT,
            'truncated' => $truncated,
        ], metrics: ['cache' => 'miss', 'ocr_pages' => $pagestoread]);
    }

    /**
     * Lookup a non-expired cache entry.
     *
     * @param string $sha
     * @param string $mechanism
     * @param execution_context $ctx
     * @return file_extraction_cache_entry|null
     */
    private function lookup_cache(string $sha, string $mechanism, execution_context $ctx): ?file_extraction_cache_entry {
        global $DB;
        $now = $ctx->clock->now()->getTimestamp();
        $record = $DB->get_record_select(
            file_extraction_cache_entry::TABLE,
            'contenthash = :sha AND mechanism = :mech AND (expires = 0 OR expires > :now)',
            ['sha' => $sha, 'mech' => $mechanism, 'now' => $now],
            '*',
            IGNORE_MISSING
        );
        if (!$record) {
            return null;
        }
        return new file_extraction_cache_entry(0, $record);
    }

    /**
     * Persist (or upsert) a cache entry.
     *
     * @param string $sha
     * @param string $mechanism
     * @param string $text
     * @param int $pages
     * @param bool $truncated
     * @param execution_context $ctx
     * @return void
     */
    private function write_cache(
        string $sha,
        string $mechanism,
        string $text,
        int $pages,
        bool $truncated,
        execution_context $ctx
    ): void {
        global $DB;
        $ttldays = (int) (get_config('local_ai_manager', 'file_extract_cache_ttl_days') ?: self::DEFAULT_CACHE_TTL_DAYS);
        $now = $ctx->clock->now()->getTimestamp();
        $expires = $ttldays > 0 ? $now + ($ttldays * DAYSECS) : 0;

        $existing = $DB->get_record(file_extraction_cache_entry::TABLE, [
            'contenthash' => $sha,
            'mechanism' => $mechanism,
        ]);
        $payload = (object) [
            'contenthash' => $sha,
            'mechanism' => $mechanism,
            'extracted_text' => $text,
            'pages' => $pages,
            'truncated' => $truncated ? 1 : 0,
            'expires' => $expires,
            'timemodified' => $now,
        ];
        if ($existing) {
            $payload->id = $existing->id;
            $DB->update_record(file_extraction_cache_entry::TABLE, $payload);
        } else {
            $payload->timecreated = $now;
            $payload->usermodified = (int) $ctx->user->id;
            $DB->insert_record(file_extraction_cache_entry::TABLE, $payload);
        }
    }
}

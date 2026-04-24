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
 * Tool: file_list_in_draft (MBS-10761 Paket 10).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent\tools\file;

use local_ai_manager\agent\execution_context;
use local_ai_manager\agent\tool_result;
use local_ai_manager\agent\tools\base_tool;

/**
 * List files in the calling user's draft area.
 *
 * Read-only, no approval. Returns filenames, paths, sizes and mimetypes for
 * every file the calling user uploaded into a given draft itemid — the typical
 * case is the chat attachment area. Useful to let the LLM decide which
 * follow-up extraction tool (file_extract_text, ...) to call.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class file_list_in_draft extends base_tool {

    #[\Override]
    public function get_name(): string {
        return 'file_list_in_draft';
    }

    #[\Override]
    public function get_summary(): string {
        return get_string('tool_file_list_in_draft_summary', 'local_ai_manager');
    }

    #[\Override]
    public function get_description(): string {
        return <<<EOT
Use this tool when you need to know which files the user has attached to the
current conversation (draft area). Typical triggers: "was habe ich hochgeladen?",
"welche Dateien sind angehängt?", and as a prelude to file_extract_text when
the LLM needs to pick the right filename.

Do NOT use this tool to list files inside a course section or activity — no
such tool exists yet; use module_list for activities. Do NOT use it on
arbitrary user draft itemids; the tool only inspects drafts belonging to the
calling user.

Behavior: Requires {draftitemid}. Returns {draftitemid, count, files:[{
filename, filepath, filesize, mimetype, source, author}]}. The list is
capped at 100 files. Ordered alphabetically by filepath + filename.

Examples:
  - "Liste meine Anhänge (draft 123)" -> file_list_in_draft({draftitemid: 123})
EOT;
    }

    #[\Override]
    public function get_category(): string {
        return 'file';
    }

    #[\Override]
    public function get_keywords(): array {
        return ['file', 'files', 'datei', 'dateien', 'attachment', 'anhang', 'draft', 'hochgeladen', 'upload'];
    }

    #[\Override]
    public function get_parameters_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'draftitemid' => [
                    'type' => 'integer',
                    'description' => 'Numeric draft itemid to inspect (belongs to the calling user).',
                    'minimum' => 1,
                ],
            ],
            'required' => ['draftitemid'],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function get_result_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'draftitemid' => ['type' => 'integer'],
                'count' => ['type' => 'integer'],
                'files' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'filename' => ['type' => 'string'],
                            'filepath' => ['type' => 'string'],
                            'filesize' => ['type' => 'integer'],
                            'mimetype' => ['type' => 'string'],
                            'source' => ['type' => 'string'],
                            'author' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }

    #[\Override]
    protected function run(array $args, execution_context $ctx): tool_result {
        $draftitemid = (int) $args['draftitemid'];
        if ($draftitemid < 1) {
            return tool_result::failure('invalid_argument',
                get_string('tool_file_list_in_draft_invalid', 'local_ai_manager'));
        }

        $usercontext = \core\context\user::instance((int) $ctx->user->id, IGNORE_MISSING);
        if (!$usercontext) {
            return tool_result::failure('user_context_missing',
                'User context could not be resolved.');
        }

        $fs = get_file_storage();
        $files = $fs->get_area_files(
            $usercontext->id,
            'user',
            'draft',
            $draftitemid,
            'filepath ASC, filename ASC',
            false
        );

        $out = [];
        foreach ($files as $f) {
            if (count($out) >= 100) {
                break;
            }
            $out[] = [
                'filename' => $f->get_filename(),
                'filepath' => $f->get_filepath(),
                'filesize' => (int) $f->get_filesize(),
                'mimetype' => (string) $f->get_mimetype(),
                'source' => (string) $f->get_source(),
                'author' => (string) $f->get_author(),
            ];
        }

        return tool_result::success([
            'draftitemid' => $draftitemid,
            'count' => count($out),
            'files' => $out,
        ]);
    }
}

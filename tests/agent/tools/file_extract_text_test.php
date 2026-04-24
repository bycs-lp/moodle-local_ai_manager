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
 * Unit tests for the file_extract_text tool (MBS-10761 SPEZ §4.7.5).
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
use local_ai_manager\agent\tool_registry;

/**
 * Tests for file_extract_text.
 *
 * @covers \local_ai_manager\agent\tools\file\file_extract_text
 * @covers \local_ai_manager\agent\itt_executor
 * @covers \local_ai_manager\agent\pdf_rasterizer
 */
final class file_extract_text_test extends \advanced_testcase {

    /**
     * Build an execution_context for the given user + system context.
     *
     * @param \stdClass $user
     * @return execution_context
     */
    private function build_exec_context(\stdClass $user): execution_context {
        return new execution_context(
            runid: 1,
            callid: 1,
            callindex: 0,
            user: $user,
            context: \core\context\system::instance(),
            tenantid: null,
            draftitemids: [],
            entity_context: [],
            clock: \core\di::get(\core\clock::class),
        );
    }

    /**
     * Create a file in the user's draft area and return the draftitemid.
     *
     * @param \stdClass $user
     * @param string $filename
     * @param string $content
     * @param string $mimetype
     * @return int draftitemid
     */
    private function make_draft_file(\stdClass $user, string $filename, string $content, string $mimetype): int {
        $usercontext = \core\context\user::instance($user->id);
        $draftitemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => $filename,
            'mimetype' => $mimetype,
        ], $content);
        return $draftitemid;
    }

    /**
     * Metadata contract linter passes.
     */
    public function test_metadata_contract(): void {
        $this->resetAfterTest();
        $tool = new file_extract_text();
        $warnings = (new tool_registry())->validate_metadata($tool);
        $this->assertSame([], $warnings, 'Warnings: ' . implode(', ', $warnings));
        $this->assertGreaterThanOrEqual(200, strlen($tool->get_description()));
        $this->assertStringContainsStringIgnoringCase('use this tool', $tool->get_description());
        $this->assertStringContainsStringIgnoringCase('do not use', $tool->get_description());
    }

    /**
     * Reading a text/plain file returns its content directly with mechanism=converter.
     */
    public function test_text_file_happy_path(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $body = "Hello world\nThis is a test.\n";
        $draft = $this->make_draft_file($user, 'note.txt', $body, 'text/plain');

        $tool = new file_extract_text();
        $result = $tool->execute(
            ['draftitemid' => $draft, 'filename' => 'note.txt'],
            $this->build_exec_context($user)
        );

        $this->assertTrue($result->ok, 'Error: ' . (string) $result->error);
        $this->assertSame($body, $result->data['text']);
        $this->assertSame(1, $result->data['pages']);
        $this->assertSame('converter', $result->data['mechanism']);
        $this->assertFalse($result->data['truncated']);
    }

    /**
     * Second call on identical content reads from the cache (mechanism preserved).
     */
    public function test_cache_hit_second_call(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $body = str_repeat("cached line\n", 20);
        $draft = $this->make_draft_file($user, 'cache.txt', $body, 'text/plain');

        $tool = new file_extract_text();
        $ctx = $this->build_exec_context($user);
        $first = $tool->execute(['draftitemid' => $draft, 'filename' => 'cache.txt'], $ctx);
        $this->assertTrue($first->ok);

        // Second invocation — same bytes, new draftitemid.
        $draft2 = $this->make_draft_file($user, 'cache2.txt', $body, 'text/plain');
        $second = $tool->execute(['draftitemid' => $draft2, 'filename' => 'cache2.txt'], $ctx);
        $this->assertTrue($second->ok);
        $this->assertSame($body, $second->data['text']);
        $this->assertArrayHasKey('cache', $second->metrics);
        $this->assertSame('hit', $second->metrics['cache']);
    }

    /**
     * Empty filename is rejected.
     */
    public function test_empty_filename_rejected(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $tool = new file_extract_text();
        $result = $tool->execute(
            ['draftitemid' => 1, 'filename' => '   '],
            $this->build_exec_context($user)
        );
        $this->assertFalse($result->ok);
        $this->assertSame('filename_missing', $result->error);
    }

    /**
     * Missing file returns file_not_found.
     */
    public function test_file_not_found(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $tool = new file_extract_text();
        $result = $tool->execute(
            ['draftitemid' => 99999, 'filename' => 'missing.txt'],
            $this->build_exec_context($user)
        );
        $this->assertFalse($result->ok);
        $this->assertSame('file_not_found', $result->error);
    }

    /**
     * Unsupported MIME types are rejected with unsupported_mime_type.
     */
    public function test_unsupported_mime_type(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        // Image: not supported by this tool (use vision purpose directly instead).
        $draft = $this->make_draft_file($user, 'pic.png', str_repeat("\x89PNG\r\n\x1a\n", 10), 'image/png');

        $tool = new file_extract_text();
        $result = $tool->execute(
            ['draftitemid' => $draft, 'filename' => 'pic.png'],
            $this->build_exec_context($user)
        );
        $this->assertFalse($result->ok);
        $this->assertSame('unsupported_mime_type', $result->error);
    }

    /**
     * PDF with force_ocr=true routes through the ITT path using the
     * mocked pdf_rasterizer + itt_executor services.
     */
    public function test_pdf_force_ocr_path(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $pdfbytes = "%PDF-1.4 mock-pdf-bytes\n";
        $draft = $this->make_draft_file($user, 'scan.pdf', $pdfbytes, 'application/pdf');

        // Mock rasterizer: pretend the PDF has 3 pages; produce 3 fake PNG files.
        $tmpdir = make_request_directory();
        $fakepngs = [];
        for ($i = 1; $i <= 3; $i++) {
            $path = $tmpdir . '/p' . $i . '.png';
            file_put_contents($path, 'fake-png-' . $i);
            $fakepngs[] = $path;
        }
        $rasterizer = new class($fakepngs) extends pdf_rasterizer {
            // phpcs:ignore moodle.Commenting.VariableComment.Missing
            private array $pngs;

            public function __construct(array $pngs) {
                $this->pngs = $pngs;
            }

            public function gs_available(): bool {
                return true;
            }

            public function get_page_count(string $pdfpath): int {
                return 3;
            }

            public function extract_text(string $pdfpath): string {
                return '';
            }

            public function rasterise_pages(string $pdfpath, int $maxpages, int $resolutiondpi = 150): array {
                return array_slice($this->pngs, 0, $maxpages);
            }
        };
        \core\di::set(pdf_rasterizer::class, $rasterizer);

        // Mock executor: return distinct text per page based on input bytes.
        $executor = new class extends itt_executor {
            // phpcs:ignore moodle.Commenting.VariableComment.Missing
            public int $calls = 0;

            public function extract(string $imagebase64, int $contextid, ?string $prompt = null): string {
                $this->calls++;
                return 'OCR-text-' . $this->calls;
            }
        };
        \core\di::set(itt_executor::class, $executor);

        $tool = new file_extract_text();
        $result = $tool->execute(
            ['draftitemid' => $draft, 'filename' => 'scan.pdf', 'force_ocr' => true],
            $this->build_exec_context($user)
        );

        $this->assertTrue($result->ok, 'Error: ' . (string) $result->error);
        $this->assertSame('itt', $result->data['mechanism']);
        $this->assertSame(3, $result->data['pages']);
        $this->assertFalse($result->data['truncated']);
        $this->assertStringContainsString('OCR-text-1', $result->data['text']);
        $this->assertStringContainsString('OCR-text-2', $result->data['text']);
        $this->assertStringContainsString('OCR-text-3', $result->data['text']);
        $this->assertSame(3, $executor->calls);
    }

    /**
     * PDF with usable embedded text: converter path wins, ITT not called.
     */
    public function test_pdf_converter_path_wins_on_dense_text(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $pdfbytes = "%PDF-1.4 dense-mock\n";
        $draft = $this->make_draft_file($user, 'lecture.pdf', $pdfbytes, 'application/pdf');

        $densetext = str_repeat('This is meaningful embedded text. ', 40); // ~1360 chars.
        $rasterizer = new class($densetext) extends pdf_rasterizer {
            // phpcs:ignore moodle.Commenting.VariableComment.Missing
            private string $text;

            public function __construct(string $text) {
                $this->text = $text;
            }

            public function gs_available(): bool {
                return true;
            }

            public function get_page_count(string $pdfpath): int {
                return 2;
            }

            public function extract_text(string $pdfpath): string {
                return $this->text;
            }

            public function rasterise_pages(string $pdfpath, int $maxpages, int $resolutiondpi = 150): array {
                return [];
            }
        };
        \core\di::set(pdf_rasterizer::class, $rasterizer);

        $executor = new class extends itt_executor {
            // phpcs:ignore moodle.Commenting.VariableComment.Missing
            public int $calls = 0;

            public function extract(string $imagebase64, int $contextid, ?string $prompt = null): string {
                $this->calls++;
                return 'should-not-be-called';
            }
        };
        \core\di::set(itt_executor::class, $executor);

        $tool = new file_extract_text();
        $result = $tool->execute(
            ['draftitemid' => $draft, 'filename' => 'lecture.pdf'],
            $this->build_exec_context($user)
        );

        $this->assertTrue($result->ok, 'Error: ' . (string) $result->error);
        $this->assertSame('converter', $result->data['mechanism']);
        $this->assertSame(2, $result->data['pages']);
        $this->assertSame($densetext, $result->data['text']);
        $this->assertSame(0, $executor->calls, 'ITT executor must not be called when embedded text is sufficient.');
    }

    /**
     * If the PDF has more pages than the configured cap, truncated=true.
     */
    public function test_pdf_page_cap_truncates(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        set_config('file_extract_max_pages', 2, 'local_ai_manager');

        $draft = $this->make_draft_file($user, 'big.pdf', "%PDF-1.4 big\n", 'application/pdf');

        $tmpdir = make_request_directory();
        $pngs = [];
        for ($i = 1; $i <= 5; $i++) {
            $p = $tmpdir . '/big' . $i . '.png';
            file_put_contents($p, 'png' . $i);
            $pngs[] = $p;
        }
        $rasterizer = new class($pngs) extends pdf_rasterizer {
            // phpcs:ignore moodle.Commenting.VariableComment.Missing
            private array $pngs;

            public function __construct(array $pngs) {
                $this->pngs = $pngs;
            }

            public function gs_available(): bool {
                return true;
            }

            public function get_page_count(string $pdfpath): int {
                return 5;
            }

            public function extract_text(string $pdfpath): string {
                return '';
            }

            public function rasterise_pages(string $pdfpath, int $maxpages, int $resolutiondpi = 150): array {
                return array_slice($this->pngs, 0, $maxpages);
            }
        };
        \core\di::set(pdf_rasterizer::class, $rasterizer);

        $executor = new class extends itt_executor {
            public function extract(string $imagebase64, int $contextid, ?string $prompt = null): string {
                return 'ocr';
            }
        };
        \core\di::set(itt_executor::class, $executor);

        $tool = new file_extract_text();
        $result = $tool->execute(
            ['draftitemid' => $draft, 'filename' => 'big.pdf', 'force_ocr' => true],
            $this->build_exec_context($user)
        );

        $this->assertTrue($result->ok);
        $this->assertSame('itt', $result->data['mechanism']);
        $this->assertSame(2, $result->data['pages']);
        $this->assertTrue($result->data['truncated']);
    }
}

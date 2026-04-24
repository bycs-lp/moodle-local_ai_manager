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
 * Injectable wrapper around the image-to-text purpose (MBS-10761 Paket 7).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent;

use local_ai_manager\manager;

/**
 * Thin adapter over manager('itt') so the file_extract_text tool can be
 * tested without a real connector. Tests bind a mock via \core\di::set().
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class itt_executor {

    /**
     * Default OCR prompt used when extracting text from rasterised pages.
     */
    public const DEFAULT_PROMPT =
        'Extract every visible text from this image as plain text. ' .
        'Preserve reading order and line breaks. ' .
        'Do not add commentary, headings, or any text that is not in the image.';

    /**
     * Run an image-to-text call via the ITT purpose.
     *
     * @param string $imagebase64 Base64-encoded image bytes (raw, no data-uri prefix).
     * @param int $contextid Context id to bill/authorise the call against.
     * @param string|null $prompt Optional override prompt, DEFAULT_PROMPT otherwise.
     * @return string Extracted text (may be empty). On error returns ''.
     */
    public function extract(string $imagebase64, int $contextid, ?string $prompt = null): string {
        $manager = new manager('itt');
        $response = $manager->perform_request(
            $prompt ?? self::DEFAULT_PROMPT,
            'local_ai_manager',
            $contextid,
            ['image' => $imagebase64],
        );
        if ($response->get_code() !== 200) {
            return '';
        }
        return (string) $response->get_content();
    }
}

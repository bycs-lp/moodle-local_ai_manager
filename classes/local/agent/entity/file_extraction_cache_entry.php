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
 * Persistent entity wrapping cached text extractions (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\local\agent\entity;

use core\persistent;

/**
 * File extraction cache entry.
 *
 * Persists the extracted text (converter path or itt-fallback) keyed by
 * sha256 contenthash, so repeated invocations of {@see file_extract_text}
 * on the same file content avoid redundant work.
 *
 * Note: The underlying table does not have usermodified/timemodified columns
 * (only timecreated). The parent {@see persistent} class would normally write
 * them automatically — we override {@see ::get_foreign_key_columns()} and
 * {@see ::add_created_modified_columns()} semantics implicitly by not
 * declaring those properties.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class file_extraction_cache_entry extends persistent {

    /** Table name. */
    public const TABLE = 'local_ai_manager_file_extract_cache';

    /** Extraction mechanism: file-converter (e.g. pdftotext). */
    public const MECHANISM_CONVERTER = 'converter';
    /** Extraction mechanism: image-to-text via an itt connector (LLM OCR). */
    public const MECHANISM_ITT = 'itt';

    #[\Override]
    protected static function define_properties(): array {
        return [
            'contenthash' => [
                'type' => PARAM_ALPHANUMEXT,
            ],
            'mechanism' => [
                'type' => PARAM_ALPHA,
                'choices' => [self::MECHANISM_CONVERTER, self::MECHANISM_ITT],
            ],
            'extracted_text' => [
                'type' => PARAM_RAW,
            ],
            'pages' => [
                'type' => PARAM_INT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'truncated' => [
                'type' => PARAM_INT,
                'default' => 0,
            ],
            'expires' => [
                'type' => PARAM_INT,
                'default' => 0,
            ],
        ];
    }
}

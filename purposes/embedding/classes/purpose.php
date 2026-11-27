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
 * Embedding purpose methods.
 *
 * @package    aipurpose_embedding
 * @copyright  2026 ISB Bayern
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aipurpose_embedding;

use local_ai_manager\base_purpose;

/**
 * Embedding purpose methods.
 *
 * @package    aipurpose_embedding
 * @copyright  2026 ISB Bayern
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purpose extends base_purpose {
    /**
     * @var string Option with several prompt texts to embed in one request instead of the single prompt text.
     *
     * If set, the prompt text has to be empty and the content of the response is a JSON array with one embedding vector per
     * prompt text in the order of the prompt texts.
     */
    public const OPTION_PROMPTTEXTS = 'prompttexts';

    #[\Override]
    public function get_additional_purpose_options(): array {
        return [self::OPTION_PROMPTTEXTS => base_purpose::PARAM_ARRAY];
    }

    #[\Override]
    public function validate_purpose_options(array $options): void {
        if (!array_key_exists(self::OPTION_PROMPTTEXTS, $options)) {
            return;
        }
        $prompttexts = $options[self::OPTION_PROMPTTEXTS];
        if (empty($prompttexts) || !array_is_list($prompttexts)) {
            throw new \coding_exception('Option ' . self::OPTION_PROMPTTEXTS . ' must be a non-empty list of prompt texts');
        }
        foreach ($prompttexts as $prompttext) {
            if (!is_string($prompttext) || trim($prompttext) === '') {
                throw new \coding_exception('Option ' . self::OPTION_PROMPTTEXTS . ' must only contain non-empty strings');
            }
        }
    }

    /**
     * Returns the raw output unchanged.
     *
     * The embedding connector returns a JSON encoded vector, not Markdown text. We must not run it
     * through the default Markdown/HTML formatting, otherwise the JSON gets mangled and is no longer
     * decodable.
     *
     * @param string $output the JSON encoded embedding vector returned by the connector
     * @return string the unmodified output
     */
    #[\Override]
    public function format_output(string $output): string {
        return $output;
    }

    /**
     * Sources are indexed with the embedding model of the user who starts the indexing, and they can only be searched with
     * the same embedding model. So all users of a tenant have to use the same embedding model.
     *
     * @return bool always true
     */
    #[\Override]
    public function requires_same_model_for_all_roles(): bool {
        return true;
    }

    #[\Override]
    public function is_internal(): bool {
        return true;
    }
}



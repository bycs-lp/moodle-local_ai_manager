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

namespace local_ai_manager\local\requestoptions;

use local_ai_manager\base_purpose;

/**
 * Definition and validation of the conversation context request option which purposes can provide.
 *
 * The connectors rely on the structure of this option when building the messages for the external AI tool.
 *
 * The content of the messages is intentionally not sanitized on input: It is raw input for the external AI tool which
 * legitimately contains code, markup or XML (for example code snippets in a chat history or the example Moodle XML in the
 * system prompt of the question generation). Only the structure is being validated by {@see self::validate}. This follows
 * the general Moodle policy to sanitize/escape data on output (for example with s(), format_string() or format_text())
 * instead of on input: Every component outputting the content of the conversation context is responsible for escaping it.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class conversationcontext {
    /** @var string The name of the option */
    public const OPTION_NAME = 'conversationcontext';

    /** @var string[] The senders the connectors are able to map to roles of the external AI tool */
    public const ALLOWED_SENDERS = ['system', 'user', 'ai'];

    /**
     * Returns the option definition to be used in base_purpose::get_additional_purpose_options.
     *
     * @return array the option definition
     */
    public function get_option_definition(): array {
        return [self::OPTION_NAME => base_purpose::PARAM_ARRAY];
    }

    /**
     * Validates that each entry of the conversation context has the structure expected by the connectors.
     *
     * The content of the messages is intentionally not cleaned, because it legitimately contains code, markup or XML.
     *
     * @param array $conversationcontext the conversation context to validate
     * @throws \coding_exception if an entry does not have the expected structure
     */
    public function validate(array $conversationcontext): void {
        foreach ($conversationcontext as $entry) {
            if (
                !is_array($entry)
                || !in_array($entry['sender'] ?? null, self::ALLOWED_SENDERS, true)
                || !is_string($entry['message'] ?? null)
            ) {
                throw new \coding_exception('Invalid entry in option ' . self::OPTION_NAME);
            }
        }
    }
}

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
 * Tool-protocol interface (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent;

/**
 * Protocol contract for the two tool-calling modes (native vs. emulated).
 *
 * The orchestrator picks one implementation based on the active connector's
 * supports_native_tool_calling() flag and delegates request/response shaping to it.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface tool_protocol {

    /** Mode identifier: 'native' or 'emulated'. */
    public const MODE_NATIVE = 'native';
    /** Mode identifier. */
    public const MODE_EMULATED = 'emulated';

    /**
     * Get the mode identifier.
     *
     * @return string
     */
    public function get_mode(): string;

    /**
     * Build the provider-specific request payload.
     *
     * @param string $systemprompt assembled system prompt (for emulated mode, this already
     *                             contains the tool catalog markdown)
     * @param array $messages chat-style conversation messages [{role, content}, ...]
     * @param array $tools schema export from tool_registry::export_schemas()
     * @return array provider-ready payload
     */
    public function build_request(string $systemprompt, array $messages, array $tools): array;

    /**
     * Parse a raw LLM response into a structured tool_response.
     *
     * @param array|string $rawresponse provider-specific response (string for emulated text output,
     *                                   array for native structured responses)
     * @return tool_response
     * @throws \local_ai_manager\agent\exception\tool_parse_exception on irrecoverable parse errors
     */
    public function parse_response(array|string $rawresponse): tool_response;
}

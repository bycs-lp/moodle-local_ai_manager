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
 * Emulated tool-protocol implementation (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent;

use local_ai_manager\agent\exception\tool_parse_exception;

/**
 * Emulated tool-calling protocol for providers without native function calls (e.g. Telli).
 *
 * The tool catalog is injected as a markdown section in the system prompt; the LLM
 * is instructed to reply with a single top-level JSON object per turn
 * ({@code {"action":"tool_call",...}} or {@code {"action":"final",...}}).
 *
 * Parsing uses a string-aware balanced-brace matcher so literal "}" characters
 * inside strings do not terminate the JSON extraction prematurely (SPEZ §5.3).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_protocol_emulated implements tool_protocol {

    #[\Override]
    public function get_mode(): string {
        return self::MODE_EMULATED;
    }

    #[\Override]
    public function build_request(string $systemprompt, array $messages, array $tools): array {
        // In emulated mode the tool catalog is already expected to be embedded in $systemprompt
        // via the system_prompt_template placeholders. The $tools parameter is retained for
        // orchestrator bookkeeping but not forwarded as a structured field.
        return [
            'messages' => array_merge(
                [['role' => 'system', 'content' => $systemprompt]],
                $messages,
            ),
        ];
    }

    #[\Override]
    public function parse_response(array|string $rawresponse): tool_response {
        $text = is_string($rawresponse)
            ? $rawresponse
            : (string) ($rawresponse['choices'][0]['message']['content'] ?? '');

        if (trim($text) === '') {
            throw new tool_parse_exception('empty_response', $text);
        }

        $json = self::extract_json_object($text);
        if ($json === null) {
            throw new tool_parse_exception('no_json_found', $text);
        }

        $parsed = json_decode($json, true);
        if (!is_array($parsed)) {
            throw new tool_parse_exception('invalid_json', $text);
        }

        $action = $parsed['action'] ?? null;
        if ($action === tool_response::ACTION_FINAL) {
            $message = $parsed['message'] ?? '';
            if (!is_string($message)) {
                throw new tool_parse_exception('final_message_not_string', $text);
            }
            return tool_response::final($message, is_array($rawresponse) ? $rawresponse : ['text' => $text]);
        }

        if ($action === tool_response::ACTION_TOOL_CALL) {
            $calls = $parsed['calls'] ?? null;
            if (!is_array($calls) || empty($calls)) {
                throw new tool_parse_exception('no_calls_in_tool_call', $text);
            }
            $normalised = [];
            foreach ($calls as $i => $call) {
                if (empty($call['tool']) || !isset($call['arguments'])) {
                    throw new tool_parse_exception('malformed_call', json_encode($call));
                }
                $normalised[] = [
                    'id' => (string) ($call['id'] ?? ('call_' . $i)),
                    'tool' => (string) $call['tool'],
                    'arguments' => is_array($call['arguments']) ? $call['arguments'] : [],
                ];
            }
            return tool_response::tool_calls($normalised, is_array($rawresponse) ? $rawresponse : ['text' => $text]);
        }

        throw new tool_parse_exception('unknown_action', $text);
    }

    /**
     * Extract the first top-level JSON object from a text blob.
     *
     * Tracks string-literal state so "}" characters inside strings do not end the object.
     * Returns null if no balanced object is found.
     *
     * @param string $text
     * @return string|null
     */
    public static function extract_json_object(string $text): ?string {
        $len = strlen($text);
        $start = -1;
        $depth = 0;
        $instring = false;
        $escape = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];

            if ($escape) {
                $escape = false;
                continue;
            }
            if ($instring) {
                if ($ch === '\\') {
                    $escape = true;
                } else if ($ch === '"') {
                    $instring = false;
                }
                continue;
            }

            if ($ch === '"') {
                $instring = true;
                continue;
            }
            if ($ch === '{') {
                if ($depth === 0) {
                    $start = $i;
                }
                $depth++;
            } else if ($ch === '}') {
                if ($depth > 0) {
                    $depth--;
                    if ($depth === 0 && $start !== -1) {
                        return substr($text, $start, $i - $start + 1);
                    }
                }
            }
        }
        return null;
    }
}

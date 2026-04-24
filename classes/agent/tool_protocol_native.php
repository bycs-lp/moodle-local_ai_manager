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
 * Native tool-protocol implementation (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent;

use local_ai_manager\agent\exception\tool_parse_exception;

/**
 * Native tool-calling protocol for providers that support it (OpenAI, Gemini, Ollama).
 *
 * The class handles the OpenAI-compatible `tools` array and `tool_calls` response field.
 * Gemini-specific `functionDeclarations` / `functionCall` shapes are handled inside the
 * respective connector adapters which call into this class after normalising the payloads
 * (see SPEZ §11).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_protocol_native implements tool_protocol {

    #[\Override]
    public function get_mode(): string {
        return self::MODE_NATIVE;
    }

    #[\Override]
    public function build_request(string $systemprompt, array $messages, array $tools): array {
        $payload = [
            'messages' => array_merge(
                [['role' => 'system', 'content' => $systemprompt]],
                $messages,
            ),
        ];
        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
            $payload['parallel_tool_calls'] = true;
        }
        return $payload;
    }

    #[\Override]
    public function parse_response(array|string $rawresponse): tool_response {
        if (is_string($rawresponse)) {
            throw new tool_parse_exception('native_requires_array', $rawresponse);
        }

        // OpenAI-compatible shape: $rawresponse['choices'][0]['message'].
        $msg = $rawresponse['choices'][0]['message'] ?? null;
        if ($msg === null) {
            // Gemini-compatible shape: candidates[0].content.parts[].
            $msg = $rawresponse['candidates'][0]['content'] ?? null;
        }
        if (!is_array($msg)) {
            throw new tool_parse_exception('missing_message', json_encode($rawresponse));
        }

        // Native tool_calls array.
        $rawcalls = $msg['tool_calls'] ?? [];
        if (!empty($rawcalls) && is_array($rawcalls)) {
            $calls = [];
            foreach ($rawcalls as $i => $call) {
                $fn = $call['function'] ?? [];
                $name = $fn['name'] ?? null;
                $argstr = $fn['arguments'] ?? '{}';
                if ($name === null) {
                    throw new tool_parse_exception('missing_tool_name', json_encode($call));
                }
                $args = is_string($argstr) ? json_decode($argstr, true) : (array) $argstr;
                if (!is_array($args)) {
                    throw new tool_parse_exception('invalid_tool_arguments', (string) $argstr);
                }
                $calls[] = [
                    'id' => (string) ($call['id'] ?? ('call_' . $i)),
                    'tool' => $name,
                    'arguments' => $args,
                ];
            }
            return tool_response::tool_calls($calls, $rawresponse);
        }

        // Gemini: content.parts[] containing functionCall entries.
        $parts = $msg['parts'] ?? null;
        if (is_array($parts)) {
            $calls = [];
            foreach ($parts as $i => $part) {
                if (isset($part['functionCall']['name'])) {
                    $calls[] = [
                        'id' => 'call_' . $i,
                        'tool' => (string) $part['functionCall']['name'],
                        'arguments' => (array) ($part['functionCall']['args'] ?? []),
                    ];
                }
            }
            if (!empty($calls)) {
                return tool_response::tool_calls($calls, $rawresponse);
            }
            // No function call but a text part -> final answer.
            $text = '';
            foreach ($parts as $part) {
                if (isset($part['text'])) {
                    $text .= (string) $part['text'];
                }
            }
            return tool_response::final($text, $rawresponse);
        }

        // Fallback: plain textual final answer.
        $content = $msg['content'] ?? null;
        if (is_string($content)) {
            return tool_response::final($content, $rawresponse);
        }

        throw new tool_parse_exception('unknown_native_shape', json_encode($rawresponse));
    }
}

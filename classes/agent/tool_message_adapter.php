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
 * Tool-message adapter for protocol transcoding (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent;

/**
 * Transcode chat history between the internal representation and provider formats.
 *
 * Internal representation (one entry per turn):
 *   ['role' => 'user'|'assistant'|'tool_call'|'tool_result',
 *    'content' => string,
 *    'tool_calls' => optional array,
 *    'tool_call_id' => optional string (for tool_result)]
 *
 * Supports conversions:
 *   internal -> native (OpenAI/Gemini style chat messages)
 *   internal -> emulated (role-based messages with embedded JSON blocks)
 *   native -> internal (for provider swaps within a conversation)
 *   emulated -> internal
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_message_adapter {

    /**
     * Internal history -> native (OpenAI-compatible) messages.
     *
     * @param array $history internal history entries
     * @param string $provider provider slug (currently unused, reserved for gemini-specific quirks)
     * @return array native messages
     */
    public function to_native(array $history, string $provider = 'openai'): array {
        $out = [];
        foreach ($history as $entry) {
            $role = $entry['role'] ?? 'user';
            if ($role === 'tool_call') {
                $out[] = [
                    'role' => 'assistant',
                    'content' => $entry['content'] ?? '',
                    'tool_calls' => $this->normalise_native_tool_calls($entry['tool_calls'] ?? []),
                ];
            } else if ($role === 'tool_result') {
                $out[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) ($entry['tool_call_id'] ?? ''),
                    'content' => (string) ($entry['content'] ?? ''),
                ];
            } else {
                $out[] = [
                    'role' => $role,
                    'content' => (string) ($entry['content'] ?? ''),
                ];
            }
        }
        return $out;
    }

    /**
     * Internal history -> emulated messages (JSON-embedded).
     *
     * @param array $history
     * @return array
     */
    public function to_emulated(array $history): array {
        $out = [];
        foreach ($history as $entry) {
            $role = $entry['role'] ?? 'user';
            if ($role === 'tool_call') {
                $payload = [
                    'action' => tool_response::ACTION_TOOL_CALL,
                    'calls' => $entry['tool_calls'] ?? [],
                ];
                $out[] = [
                    'role' => 'assistant',
                    'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            } else if ($role === 'tool_result') {
                $payload = [
                    'action' => 'tool_result',
                    'results' => [[
                        'id' => (string) ($entry['tool_call_id'] ?? ''),
                        'content' => $entry['content'] ?? '',
                    ]],
                ];
                $out[] = [
                    'role' => 'user',
                    'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            } else {
                $out[] = [
                    'role' => $role,
                    'content' => (string) ($entry['content'] ?? ''),
                ];
            }
        }
        return $out;
    }

    /**
     * Native (OpenAI-style) messages -> internal history.
     *
     * @param array $providermsgs
     * @param string $provider
     * @return array
     */
    public function from_native(array $providermsgs, string $provider = 'openai'): array {
        $out = [];
        foreach ($providermsgs as $msg) {
            $role = $msg['role'] ?? 'user';
            if ($role === 'assistant' && !empty($msg['tool_calls'])) {
                $out[] = [
                    'role' => 'tool_call',
                    'content' => (string) ($msg['content'] ?? ''),
                    'tool_calls' => $this->normalise_native_tool_calls($msg['tool_calls']),
                ];
            } else if ($role === 'tool') {
                $out[] = [
                    'role' => 'tool_result',
                    'tool_call_id' => (string) ($msg['tool_call_id'] ?? ''),
                    'content' => (string) ($msg['content'] ?? ''),
                ];
            } else {
                $out[] = [
                    'role' => $role,
                    'content' => (string) ($msg['content'] ?? ''),
                ];
            }
        }
        return $out;
    }

    /**
     * Emulated messages -> internal history.
     *
     * @param array $messages
     * @return array
     */
    public function from_emulated(array $messages): array {
        $out = [];
        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = (string) ($msg['content'] ?? '');
            $json = tool_protocol_emulated::extract_json_object($content);
            $parsed = $json !== null ? json_decode($json, true) : null;
            if (is_array($parsed) && ($parsed['action'] ?? null) === tool_response::ACTION_TOOL_CALL) {
                $out[] = [
                    'role' => 'tool_call',
                    'content' => '',
                    'tool_calls' => (array) ($parsed['calls'] ?? []),
                ];
            } else if (is_array($parsed) && ($parsed['action'] ?? null) === 'tool_result') {
                foreach ((array) ($parsed['results'] ?? []) as $r) {
                    $out[] = [
                        'role' => 'tool_result',
                        'tool_call_id' => (string) ($r['id'] ?? ''),
                        'content' => is_scalar($r['content'] ?? null) ? (string) $r['content']
                            : json_encode($r['content'] ?? null),
                    ];
                }
            } else {
                $out[] = ['role' => $role, 'content' => $content];
            }
        }
        return $out;
    }

    /**
     * Normalise internal tool-call records to the OpenAI-native shape.
     *
     * @param array $calls
     * @return array
     */
    private function normalise_native_tool_calls(array $calls): array {
        $out = [];
        foreach ($calls as $call) {
            $args = $call['arguments'] ?? [];
            $out[] = [
                'id' => (string) ($call['id'] ?? ''),
                'type' => 'function',
                'function' => [
                    'name' => (string) ($call['tool'] ?? ''),
                    'arguments' => is_string($args) ? $args
                        : json_encode($args, JSON_UNESCAPED_UNICODE),
                ],
            ];
        }
        return $out;
    }
}

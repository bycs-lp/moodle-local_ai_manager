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
 * Scripted LLM client for orchestrator tests (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent\tests\fixtures;

use local_ai_manager\agent\llm_client;

/**
 * Test-only implementation of {@see llm_client} that replays a scripted response sequence.
 *
 * Each call to {@see send()} consumes the next scripted response. Scripts may contain
 * raw arrays (native mode) or strings (emulated mode). A script may also include
 * {@see \Throwable} instances, which are rethrown to simulate transport failures.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class fake_llm_client implements llm_client {

    /** @var array calls received so far (for assertions). */
    public array $received = [];

    /**
     * Constructor.
     *
     * @param array $script ordered list of responses (array|string|\Throwable)
     * @param string $connectorname
     * @param string|null $model
     */
    public function __construct(
        private array $script,
        private readonly string $connectorname = 'fake',
        private readonly ?string $model = 'fake-model',
    ) {
    }

    /**
     * Build a native-mode final-answer response.
     *
     * @param string $text
     * @return array
     */
    public static function final_native(string $text): array {
        return [
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => $text]],
            ],
        ];
    }

    /**
     * Build a native-mode tool_calls response.
     *
     * @param array $calls list of [{id, name, arguments}]
     * @return array
     */
    public static function tool_calls_native(array $calls): array {
        $toolcalls = [];
        foreach ($calls as $i => $call) {
            $toolcalls[] = [
                'id' => (string) ($call['id'] ?? 'call_' . $i),
                'type' => 'function',
                'function' => [
                    'name' => (string) $call['name'],
                    'arguments' => json_encode((array) ($call['arguments'] ?? [])),
                ],
            ];
        }
        return [
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => $toolcalls]],
            ],
        ];
    }

    #[\Override]
    public function send(array $payload): array|string {
        $this->received[] = $payload;
        if (empty($this->script)) {
            throw new \coding_exception('fake_llm_client script exhausted.');
        }
        $next = array_shift($this->script);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next;
    }

    #[\Override]
    public function get_connector_name(): string {
        return $this->connectorname;
    }

    #[\Override]
    public function get_model(): ?string {
        return $this->model;
    }
}

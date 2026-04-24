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
 * LLM client abstraction for the tool-agent orchestrator (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent;

/**
 * Minimal request/response contract the orchestrator uses to talk to a model.
 *
 * The production adapter wraps a {@see \local_ai_manager\base_connector};
 * the {@see \local_ai_manager\agent\tests\connector_fake} test helper implements
 * this interface directly to drive scripted agent runs without HTTP.
 *
 * The payload contract matches the output of {@see tool_protocol::build_request()}:
 *   ['messages' => [ ... ], 'tools' => [ ... ] (optional), ...]
 *
 * The return value is forwarded to {@see tool_protocol::parse_response()} unchanged.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface llm_client {

    /**
     * Issue a single request to the LLM and return the raw response.
     *
     * Implementations MUST throw {@see \moodle_exception} on transport-level
     * failures — the orchestrator converts those into failed run_result objects.
     *
     * @param array $payload provider-ready payload produced by the tool protocol
     * @return array|string provider response (array for native providers, string for emulated text)
     */
    public function send(array $payload): array|string;

    /**
     * Connector slug used for logging in agent_run.connector (e.g. 'chatgpt').
     *
     * @return string
     */
    public function get_connector_name(): string;

    /**
     * Model id used for logging in agent_run.model (e.g. 'gpt-4o-mini').
     *
     * @return string|null
     */
    public function get_model(): ?string;
}

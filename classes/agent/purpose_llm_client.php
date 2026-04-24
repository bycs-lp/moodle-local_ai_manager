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
 * Production LLM client adapter for the tool-agent orchestrator (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent;

use local_ai_manager\base_connector;
use local_ai_manager\base_purpose;
use local_ai_manager\request_options;

/**
 * Adapter that turns a configured {@see base_connector} into a {@see llm_client}.
 *
 * The orchestrator already produces provider-ready payloads through the
 * tool_protocol; we therefore bypass the connector's prompt-composition layer
 * and POST the payload as-is via {@see base_connector::make_request()}. The raw
 * JSON body is decoded to an associative array and handed back to the protocol
 * for parsing.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purpose_llm_client implements llm_client {

    /**
     * Constructor.
     *
     * @param base_connector $connector resolved by the connector factory for the toolagent purpose
     * @param base_purpose $purpose purpose instance (aipurpose_toolagent\purpose)
     * @param \core\context $context context the request is running in
     * @param string $component frankenstyle component name of the caller
     */
    public function __construct(
        private readonly base_connector $connector,
        private readonly base_purpose $purpose,
        private readonly \core\context $context,
        private readonly string $component,
    ) {
    }

    #[\Override]
    public function send(array $payload): array|string {
        $options = new request_options($this->purpose, $this->context, $this->component, []);
        // Let the connector produce its provider-ready envelope (model, temperature,
        // auth-specific fields, ...) with a throw-away prompt. We then keep every
        // field the connector contributed *except* `messages`, and layer our own
        // orchestrator-built `messages` + `tools` on top.
        $envelope = $this->connector->get_prompt_data('', $options);
        unset($envelope['messages']);
        $payload = $envelope + $payload;

        $response = $this->connector->make_request($payload, $options);
        if ($response->get_code() !== 200) {
            throw new \moodle_exception(
                'agent_llm_request_failed',
                'local_ai_manager',
                '',
                null,
                $response->get_errormessage() . ' (code=' . $response->get_code() . '): '
                    . substr($response->get_debuginfo(), 0, 2000),
            );
        }
        $body = (string) $response->get_response();
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \moodle_exception(
                'agent_llm_request_failed',
                'local_ai_manager',
                '',
                null,
                'Non-JSON response from LLM: ' . substr($body, 0, 200),
            );
        }
        return $decoded;
    }

    #[\Override]
    public function get_connector_name(): string {
        return $this->connector->get_instance()->get_connector();
    }

    #[\Override]
    public function get_model(): ?string {
        $model = $this->connector->get_instance()->get_model();
        return $model !== '' ? $model : null;
    }

    #[\Override]
    public function supports_native_tool_calling(): bool {
        return $this->connector->supports_native_tool_calling();
    }
}

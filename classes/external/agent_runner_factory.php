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
 * Factory that assembles an orchestrator for a concrete caller (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\external;

use local_ai_manager\agent\injection_guard;
use local_ai_manager\agent\llm_client;
use local_ai_manager\agent\orchestrator;
use local_ai_manager\agent\purpose_llm_client;
use local_ai_manager\agent\tool_protocol;
use local_ai_manager\agent\tool_protocol_emulated;
use local_ai_manager\agent\tool_protocol_native;
use local_ai_manager\agent\tool_registry;
use local_ai_manager\agent\trust_resolver;
use local_ai_manager\local\connector_factory;
use local_ai_manager\local\userinfo;

/**
 * DI seam for the agent_run_start external function.
 *
 * Keeps the wiring in one place so it can be replaced in tests via
 * {@see \core\di::set()}. Default implementation raises `agent_runner_disabled`
 * because block_ai_chat must supply the concrete llm_client binding; this
 * happens in Baustein 7's frontend patch which is tracked separately.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class agent_runner_factory {

    /**
     * Build an orchestrator instance bound to the given caller context.
     *
     * @param string $component frankenstyle component that invokes the agent
     * @param \core\context $context
     * @return orchestrator
     * @throws \moodle_exception on missing wiring
     */
    public function build(string $component, \core\context $context): orchestrator {
        global $USER;
        $client = $this->resolve_llm_client($component, $context);
        if ($client === null) {
            throw new \moodle_exception('agent_runner_disabled', 'local_ai_manager');
        }
        $protocol = $this->resolve_protocol($client);
        $registry = \core\di::get(tool_registry::class);
        $tools = $registry->get_tools_for($USER, $context);
        return new orchestrator(
            client: $client,
            protocol: $protocol,
            availabletools: $tools,
            clock: \core\di::get(\core\clock::class),
            trustresolver: new trust_resolver(),
            injectionguard: new injection_guard(),
        );
    }

    /**
     * Resolve the tool protocol (native vs. emulated) for the given client.
     *
     * Per SPEZ §11 the decision is driven by the connector's declared
     * capability: a connector that reports {@see llm_client::supports_native_tool_calling()}
     * drives the native OpenAI/Gemini/Ollama shape; everything else falls
     * back to the emulated JSON-block protocol. Tests may pre-register a
     * specific {@see tool_protocol} via {@see \core\di::set()} to force either
     * shape — that override is honoured unconditionally.
     *
     * @param llm_client $client
     * @return tool_protocol
     */
    protected function resolve_protocol(llm_client $client): tool_protocol {
        try {
            $forced = \core\di::get(tool_protocol::class);
            if ($forced instanceof tool_protocol) {
                return $forced;
            }
        } catch (\Throwable) {
            // Fall through to connector-driven selection.
        }
        return $client->supports_native_tool_calling()
            ? new tool_protocol_native()
            : new tool_protocol_emulated();
    }

    /**
     * Resolve the LLM client for the given component. Returns null when no
     * toolagent connector is configured for the caller's tenant role.
     *
     * The default implementation picks the connector the tenant has configured
     * for the 'toolagent' purpose via {@see connector_factory::get_connector_by_purpose()}.
     * Tests override this method (or the whole factory) via
     * {@see \core\di::set()} to inject a scripted {@see \local_ai_manager\agent\tests\connector_fake}.
     *
     * @param string $component
     * @param \core\context $context
     * @return llm_client|null
     */
    protected function resolve_llm_client(string $component, \core\context $context): ?llm_client {
        global $USER;
        try {
            // Tests may pre-register an llm_client directly via DI — honour that first.
            $direct = \core\di::get(llm_client::class);
            if ($direct instanceof llm_client) {
                return $direct;
            }
        } catch (\Throwable) {
            // Fall through to the production lookup.
            $direct = null;
        }
        try {
            $factory = \core\di::get(connector_factory::class);
            $userinfo = new userinfo((int) $USER->id);
            $connector = $factory->get_connector_by_purpose('toolagent', $userinfo->get_role());
            if ($connector === null) {
                return null;
            }
            $purpose = $factory->get_purpose_by_purpose_string('toolagent');
            return new purpose_llm_client($connector, $purpose, $context, $component);
        } catch (\Throwable) {
            return null;
        }
    }
}

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
 * Agent runner factory tests (MBS-10761 Paket 5).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/ai_manager/tests/agent/fixtures/fake_llm_client.php');

use local_ai_manager\agent\llm_client;
use local_ai_manager\agent\tests\fixtures\fake_llm_client;
use local_ai_manager\agent\tool_protocol;
use local_ai_manager\agent\tool_protocol_emulated;
use local_ai_manager\agent\tool_protocol_native;

/**
 * Validates the native-vs-emulated protocol selection driven by
 * {@see llm_client::supports_native_tool_calling()} per SPEZ §11.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_manager\external\agent_runner_factory
 */
final class agent_runner_factory_test extends \advanced_testcase {

    /**
     * Returns the factory subclass that exposes the protected
     * resolve_protocol() helper for direct testing.
     *
     * @return agent_runner_factory
     */
    private function factory(): agent_runner_factory {
        return new class extends agent_runner_factory {
            /**
             * Expose the protected protocol resolver.
             *
             * @param llm_client $c
             * @return tool_protocol
             */
            public function resolve_protocol_public(llm_client $c): tool_protocol {
                return $this->resolve_protocol($c);
            }
        };
    }

    /**
     * Native-capable connector must drive the native protocol shape.
     */
    public function test_native_client_picks_native_protocol(): void {
        $this->resetAfterTest();
        $client = new fake_llm_client([], 'chatgpt', 'gpt-4o-mini', nativetoolcalling: true);
        $protocol = $this->factory()->resolve_protocol_public($client);
        $this->assertInstanceOf(tool_protocol_native::class, $protocol);
        $this->assertSame(tool_protocol::MODE_NATIVE, $protocol->get_mode());
    }

    /**
     * Connectors without native tool calling fall back to the emulated JSON
     * block protocol.
     */
    public function test_non_native_client_picks_emulated_protocol(): void {
        $this->resetAfterTest();
        $client = new fake_llm_client([], 'telli', 'telli-model', nativetoolcalling: false);
        $protocol = $this->factory()->resolve_protocol_public($client);
        $this->assertInstanceOf(tool_protocol_emulated::class, $protocol);
        $this->assertSame(tool_protocol::MODE_EMULATED, $protocol->get_mode());
    }

    /**
     * An explicit DI override must take precedence over the connector
     * capability to support deterministic test wiring.
     */
    public function test_di_override_wins_over_connector_capability(): void {
        $this->resetAfterTest();
        \core\di::set(tool_protocol::class, new tool_protocol_emulated());
        $client = new fake_llm_client([], 'chatgpt', 'gpt-4o-mini', nativetoolcalling: true);
        $protocol = $this->factory()->resolve_protocol_public($client);
        $this->assertInstanceOf(tool_protocol_emulated::class, $protocol);
    }
}

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
 * Tests for agent_run_start external function (MBS-10761 Baustein 7).
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
require_once($CFG->dirroot . '/local/ai_manager/tests/agent/fixtures/fake_tool.php');

use local_ai_manager\agent\injection_guard;
use local_ai_manager\agent\llm_client;
use local_ai_manager\agent\orchestrator;
use local_ai_manager\agent\tests\fixtures\fake_llm_client;
use local_ai_manager\agent\tests\fixtures\fake_tool;
use local_ai_manager\agent\tool_protocol;
use local_ai_manager\agent\tool_protocol_native;
use local_ai_manager\agent\trust_resolver;

/**
 * Tests for agent_run_start external function.
 *
 * @covers \local_ai_manager\external\agent_run_start
 * @covers \local_ai_manager\external\agent_runner_factory
 */
final class agent_run_start_test extends \advanced_testcase {

    /**
     * Build a runner factory whose resolve_llm_client() returns a fake client.
     *
     * @param fake_llm_client $client
     * @return agent_runner_factory
     */
    private function build_factory(fake_llm_client $client): agent_runner_factory {
        return new class ($client) extends agent_runner_factory {
            /** @var fake_llm_client */
            private $client;
            /**
             * @param fake_llm_client $client
             */
            public function __construct(fake_llm_client $client) {
                $this->client = $client;
            }
            #[\Override]
            public function build(string $component, \core\context $context): orchestrator {
                return new orchestrator(
                    client: $this->client,
                    protocol: new tool_protocol_native(),
                    availabletools: [new fake_tool('fake_tool', false)],
                    clock: \core\di::get(\core\clock::class),
                    trustresolver: new trust_resolver(),
                    injectionguard: new injection_guard(),
                );
            }
        };
    }

    /**
     * Runs a happy-path agent invocation end-to-end via the external API.
     */
    public function test_execute_returns_complete_status_for_final_answer(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $client = new fake_llm_client([
            fake_llm_client::final_native('Hallo Welt.'),
        ]);
        \core\di::set(agent_runner_factory::class, $this->build_factory($client));

        $contextid = \core\context\system::instance()->id;
        $result = agent_run_start::execute('block_ai_chat', $contextid, 'Grüß die Welt.');
        $result = \core_external\external_api::clean_returnvalue(
            agent_run_start::execute_returns(),
            $result,
        );

        $this->assertSame('completed', $result['status']);
        $this->assertSame('Hallo Welt.', $result['final_text']);
        $this->assertSame([], $result['pending_approvals']);
        $this->assertGreaterThan(0, $result['runid']);
    }

    /**
     * Rejects calls with neither userprompt nor runid.
     */
    public function test_execute_requires_prompt_or_runid(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $contextid = \core\context\system::instance()->id;

        $this->expectException(\invalid_parameter_exception::class);
        agent_run_start::execute('block_ai_chat', $contextid, '', 0, 0);
    }

    /**
     * Without wiring the factory returns `agent_runner_disabled`.
     */
    public function test_execute_fails_when_runner_not_wired(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $contextid = \core\context\system::instance()->id;

        // Register a factory whose resolve_llm_client() returns null to simulate
        // the pre-wiring state that block_ai_chat still has to complete.
        $nowiring = new class extends agent_runner_factory {
            #[\Override]
            protected function resolve_llm_client(string $component, \core\context $context): ?llm_client {
                return null;
            }
        };
        \core\di::set(agent_runner_factory::class, $nowiring);

        $this->expectException(\moodle_exception::class);
        agent_run_start::execute('block_ai_chat', $contextid, 'hello');
    }
}

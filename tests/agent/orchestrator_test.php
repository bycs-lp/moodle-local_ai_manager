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
 * Unit tests for the tool-agent orchestrator (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent;

use local_ai_manager\agent\tests\fixtures\fake_llm_client;
use local_ai_manager\agent\tests\fixtures\fake_tool;
use local_ai_manager\local\agent\entity\agent_run;
use local_ai_manager\local\agent\entity\tool_call;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/ai_manager/tests/agent/fixtures/fake_llm_client.php');
require_once($CFG->dirroot . '/local/ai_manager/tests/agent/fixtures/fake_tool.php');

/**
 * Tests the orchestrator reason + act loop against scripted fake LLM responses.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_manager\agent\orchestrator
 */
final class orchestrator_test extends \advanced_testcase {

    /**
     * Build a fresh user + system context for a run.
     *
     * @return array{0: \stdClass, 1: \core\context}
     */
    private function build_subject(): array {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        return [$user, \core\context\system::instance()];
    }

    /**
     * A one-shot final answer should produce a COMPLETED run without any tool calls.
     */
    public function test_run_final_answer_completes_run(): void {
        $this->resetAfterTest();
        $this->mock_clock_with_frozen(1700000000);

        [$user, $context] = $this->build_subject();
        $client = new fake_llm_client([
            fake_llm_client::final_native('Here is my answer.'),
        ]);

        $orchestrator = new orchestrator(
            client: $client,
            protocol: new tool_protocol_native(),
            availabletools: [],
            clock: \core\di::get(\core\clock::class),
        );

        $result = $orchestrator->run($user, $context, 'Hello agent.');

        $this->assertTrue($result->is_complete());
        $this->assertSame('Here is my answer.', $result->final_text);
        $this->assertSame(1, $result->iterations);

        $run = new agent_run($result->runid);
        $this->assertSame(agent_run::STATUS_COMPLETED, $run->get('status'));
        $this->assertSame('Hello agent.', $run->get('user_prompt'));
        $this->assertCount(1, $client->received);
    }

    /**
     * Auto-approved read-only tool: executes in one iteration, LLM then returns final.
     */
    public function test_run_auto_approved_tool_then_final(): void {
        $this->resetAfterTest();
        $this->mock_clock_with_frozen(1700000000);

        [$user, $context] = $this->build_subject();
        $tool = new fake_tool(name: 'read_course', requiresapproval: false);
        $client = new fake_llm_client([
            fake_llm_client::tool_calls_native([
                ['id' => 'call_1', 'name' => 'read_course', 'arguments' => ['courseid' => 12]],
            ]),
            fake_llm_client::final_native('Course 12 loaded.'),
        ]);

        $orchestrator = new orchestrator(
            client: $client,
            protocol: new tool_protocol_native(),
            availabletools: [$tool],
            clock: \core\di::get(\core\clock::class),
        );

        $result = $orchestrator->run($user, $context, 'Show me course 12.');

        $this->assertTrue($result->is_complete());
        $this->assertSame('Course 12 loaded.', $result->final_text);
        $this->assertCount(1, $tool->invocations);
        $this->assertSame(['courseid' => 12], $tool->invocations[0]['args']);

        $calls = tool_call::get_records(['runid' => $result->runid], 'callindex', 'ASC');
        $this->assertCount(1, $calls);
        $row = reset($calls);
        $this->assertSame('read_course', $row->get('toolname'));
        $this->assertSame(tool_call::APPROVAL_AUTO, $row->get('approval_state'));
        $this->assertNotNull($row->get('result_json'));
    }

    /**
     * When a tool requires approval the run pauses with a pending_approvals payload.
     */
    public function test_run_pauses_for_approval_when_required(): void {
        $this->resetAfterTest();
        $this->mock_clock_with_frozen(1700000000);

        [$user, $context] = $this->build_subject();
        $tool = new fake_tool(
            name: 'delete_course',
            requiresapproval: true,
            affectedobjects: [['type' => 'course', 'id' => 42]],
        );
        $client = new fake_llm_client([
            fake_llm_client::tool_calls_native([
                ['id' => 'call_1', 'name' => 'delete_course', 'arguments' => ['courseid' => 42]],
            ]),
            // Second response only consumed after resume().
            fake_llm_client::final_native('Course 42 deleted.'),
        ]);

        $orchestrator = new orchestrator(
            client: $client,
            protocol: new tool_protocol_native(),
            availabletools: [$tool],
            clock: \core\di::get(\core\clock::class),
        );

        $result = $orchestrator->run($user, $context, 'Delete course 42.');

        $this->assertTrue($result->is_awaiting_approval());
        $this->assertCount(1, $result->pending_approvals);
        $pending = $result->pending_approvals[0];
        $this->assertSame('delete_course', $pending['tool']);
        $this->assertSame(['courseid' => 42], $pending['args']);
        $this->assertNotEmpty($pending['token']);
        $this->assertCount(0, $tool->invocations, 'Tool must not execute before approval.');

        // User approves the call in the DB; then resume.
        $callrow = new tool_call($pending['callid']);
        $callrow->set('approval_state', tool_call::APPROVAL_APPROVED);
        $callrow->set('approved_by', (int) $user->id);
        $callrow->set('approved_at', \core\di::get(\core\clock::class)->now()->getTimestamp());
        $callrow->save();

        $resumed = $orchestrator->resume($result->runid, $user, $context);
        $this->assertTrue($resumed->is_complete());
        $this->assertSame('Course 42 deleted.', $resumed->final_text);
        $this->assertCount(1, $tool->invocations);
    }

    /**
     * An unknown tool name is surfaced as a synthetic tool_result and the LLM can recover.
     */
    public function test_run_unknown_tool_surfaces_error_without_abort(): void {
        $this->resetAfterTest();
        $this->mock_clock_with_frozen(1700000000);

        [$user, $context] = $this->build_subject();
        $client = new fake_llm_client([
            fake_llm_client::tool_calls_native([
                ['id' => 'call_1', 'name' => 'does_not_exist', 'arguments' => []],
            ]),
            fake_llm_client::final_native('Sorry, that tool is not available.'),
        ]);

        $orchestrator = new orchestrator(
            client: $client,
            protocol: new tool_protocol_native(),
            availabletools: [],
            clock: \core\di::get(\core\clock::class),
        );

        $result = $orchestrator->run($user, $context, 'Try unknown.');
        $this->assertTrue($result->is_complete());
        $this->assertStringContainsString('not available', $result->final_text);
    }

    /**
     * Max-iterations is enforced and surfaced as ABORTED_MAXITER.
     */
    public function test_run_max_iterations_aborts(): void {
        $this->resetAfterTest();
        $this->mock_clock_with_frozen(1700000000);
        set_config('agent_max_iterations', 2, 'local_ai_manager');

        [$user, $context] = $this->build_subject();
        $tool = new fake_tool(name: 'ping');
        // Client keeps asking for the same tool forever.
        $client = new fake_llm_client(array_fill(0, 5, fake_llm_client::tool_calls_native([
            ['id' => 'call_x', 'name' => 'ping', 'arguments' => []],
        ])));

        $orchestrator = new orchestrator(
            client: $client,
            protocol: new tool_protocol_native(),
            availabletools: [$tool],
            clock: \core\di::get(\core\clock::class),
        );

        $result = $orchestrator->run($user, $context, 'Loop.');
        $this->assertSame(agent_run::STATUS_ABORTED_MAXITER, $result->status);
        $this->assertSame('max_iterations_reached', $result->error_code);
    }

    /**
     * A parse failure triggers self-correction; after correction the run completes.
     */
    public function test_run_self_corrects_after_parse_exception(): void {
        $this->resetAfterTest();
        $this->mock_clock_with_frozen(1700000000);

        [$user, $context] = $this->build_subject();
        // First response is a string (invalid for native) -> parse exception.
        // Second response is a valid final answer -> completes.
        $client = new fake_llm_client([
            'this is not a valid native response',
            fake_llm_client::final_native('Recovered.'),
        ]);

        $orchestrator = new orchestrator(
            client: $client,
            protocol: new tool_protocol_native(),
            availabletools: [],
            clock: \core\di::get(\core\clock::class),
        );

        $result = $orchestrator->run($user, $context, 'Recover.');
        $this->assertTrue($result->is_complete());
        $this->assertSame('Recovered.', $result->final_text);
    }

    /**
     * Transport-level exception from the LLM client -> FAILED run.
     */
    public function test_run_llm_transport_error_fails_run(): void {
        $this->resetAfterTest();
        $this->mock_clock_with_frozen(1700000000);

        [$user, $context] = $this->build_subject();
        $client = new fake_llm_client([
            new \moodle_exception('error_http500', 'local_ai_manager'),
        ]);

        $orchestrator = new orchestrator(
            client: $client,
            protocol: new tool_protocol_native(),
            availabletools: [],
            clock: \core\di::get(\core\clock::class),
        );

        $result = $orchestrator->run($user, $context, 'Fail.');
        $this->assertSame(agent_run::STATUS_FAILED, $result->status);
        $this->assertSame('llm_transport_error', $result->error_code);
    }

    /**
     * Parallel tool calls in one LLM response are executed in order and surfaced to the LLM.
     */
    public function test_run_executes_parallel_tool_calls(): void {
        $this->resetAfterTest();
        $this->mock_clock_with_frozen(1700000000);

        [$user, $context] = $this->build_subject();
        $tool = new fake_tool(name: 'lookup', requiresapproval: false);
        $client = new fake_llm_client([
            fake_llm_client::tool_calls_native([
                ['id' => 'c1', 'name' => 'lookup', 'arguments' => ['q' => 'a']],
                ['id' => 'c2', 'name' => 'lookup', 'arguments' => ['q' => 'b']],
            ]),
            fake_llm_client::final_native('Both done.'),
        ]);

        $orchestrator = new orchestrator(
            client: $client,
            protocol: new tool_protocol_native(),
            availabletools: [$tool],
            clock: \core\di::get(\core\clock::class),
        );

        $result = $orchestrator->run($user, $context, 'Two calls.');
        $this->assertTrue($result->is_complete());
        $this->assertCount(2, $tool->invocations);
        $this->assertSame(['q' => 'a'], $tool->invocations[0]['args']);
        $this->assertSame(['q' => 'b'], $tool->invocations[1]['args']);

        $calls = tool_call::get_records(['runid' => $result->runid], 'callindex', 'ASC');
        $this->assertCount(2, $calls);
    }

    /**
     * Rejection of the same tool past the configured retry limit aborts the run.
     */
    public function test_rejection_limit_aborts_run(): void {
        $this->resetAfterTest();
        $this->mock_clock_with_frozen(1700000000);
        set_config('agent_rejection_retry_limit', 1, 'local_ai_manager');

        [$user, $context] = $this->build_subject();
        $tool = new fake_tool(
            name: 'destructive_op',
            requiresapproval: true,
            affectedobjects: [['type' => 'course', 'id' => 7]],
        );
        $client = new fake_llm_client([
            fake_llm_client::tool_calls_native([
                ['id' => 'c1', 'name' => 'destructive_op', 'arguments' => []],
            ]),
        ]);

        $orchestrator = new orchestrator(
            client: $client,
            protocol: new tool_protocol_native(),
            availabletools: [$tool],
            clock: \core\di::get(\core\clock::class),
        );

        $result = $orchestrator->run($user, $context, 'Ask destructively.');
        $this->assertTrue($result->is_awaiting_approval());

        // User rejects.
        $callrow = new tool_call($result->pending_approvals[0]['callid']);
        $callrow->set('approval_state', tool_call::APPROVAL_REJECTED);
        $callrow->save();

        $resumed = $orchestrator->resume($result->runid, $user, $context);
        $this->assertSame(agent_run::STATUS_FAILED, $resumed->status);
        $this->assertSame('rejection_limit_reached', $resumed->error_code);
    }
}

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
 * Tests for agent_abort_run external function (MBS-10761 Paket 2).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\external;

use local_ai_manager\local\agent\entity\agent_run;
use local_ai_manager\local\agent\entity\tool_call;

/**
 * Tests for the abort_run external function.
 *
 * @covers \local_ai_manager\external\agent_abort_run
 */
final class agent_abort_run_test extends \advanced_testcase {

    /**
     * Helper to create a minimal agent_run owned by $userid.
     *
     * @param int $userid
     * @param string $status
     * @return agent_run
     */
    private function make_run(int $userid, string $status = agent_run::STATUS_RUNNING): agent_run {
        $run = new agent_run(0, (object) [
            'userid' => $userid,
            'contextid' => \core\context\system::instance()->id,
            'tenantid' => null,
            'component' => 'block_ai_chat',
            'mode' => agent_run::MODE_NATIVE,
            'connector' => 'chatgpt',
            'status' => $status,
        ]);
        $run->create();
        return $run;
    }

    /**
     * Aborting a running run transitions it to STATUS_ABORTED_USER.
     */
    public function test_abort_running_run(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $run = $this->make_run((int) $user->id);
        $result = agent_abort_run::execute((int) $run->get('id'));

        $this->assertTrue($result['aborted']);
        $this->assertSame(agent_run::STATUS_ABORTED_USER, $result['status']);

        $reloaded = new agent_run((int) $run->get('id'));
        $this->assertSame(agent_run::STATUS_ABORTED_USER, $reloaded->get('status'));
        $this->assertGreaterThan(0, (int) $reloaded->get('finished'));
    }

    /**
     * Aborting a run that is already terminal is a no-op.
     */
    public function test_abort_terminal_run_is_noop(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $run = $this->make_run((int) $user->id, agent_run::STATUS_COMPLETED);
        $result = agent_abort_run::execute((int) $run->get('id'));

        $this->assertFalse($result['aborted']);
        $this->assertSame(agent_run::STATUS_COMPLETED, $result['status']);
    }

    /**
     * Aborting a run also rejects any awaiting-approval tool calls.
     */
    public function test_abort_rejects_pending_calls(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $run = $this->make_run((int) $user->id, agent_run::STATUS_AWAITING_APPROVAL);
        $call = new tool_call(0, (object) [
            'runid' => (int) $run->get('id'),
            'callindex' => 0,
            'toolname' => 'course_create',
            'args_json' => '{}',
            'args_hash' => 'deadbeef',
            'approval_state' => tool_call::APPROVAL_AWAITING,
        ]);
        $call->create();

        agent_abort_run::execute((int) $run->get('id'));

        $reloaded = new tool_call((int) $call->get('id'));
        $this->assertSame(tool_call::APPROVAL_REJECTED, $reloaded->get('approval_state'));
    }

    /**
     * A non-owner cannot abort someone else's run.
     */
    public function test_abort_rejects_non_owner(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->setUser($other);

        $run = $this->make_run((int) $owner->id);

        $this->expectException(\required_capability_exception::class);
        agent_abort_run::execute((int) $run->get('id'));
    }
}

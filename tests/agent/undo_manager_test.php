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
 * Tests for undo_manager (MBS-10761 Paket 2).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent;

use local_ai_manager\local\agent\entity\tool_call;

/**
 * Tests for register() + can_undo() window logic.
 *
 * @covers \local_ai_manager\agent\undo_manager
 */
final class undo_manager_test extends \advanced_testcase {

    /**
     * Helper: build a persisted tool_call with a known timecreated.
     *
     * @param int $age seconds in the past
     * @return tool_call
     */
    private function make_call(int $age = 0): tool_call {
        $call = new tool_call(0, (object) [
            'runid' => 0,
            'callindex' => 0,
            'toolname' => 'course_create',
            'args_json' => '{}',
            'args_hash' => 'abc',
        ]);
        $call->create();
        if ($age > 0) {
            global $DB;
            $DB->set_field(tool_call::TABLE, 'timecreated', time() - $age, ['id' => (int) $call->get('id')]);
            $call = new tool_call((int) $call->get('id'));
        }
        return $call;
    }

    /**
     * register() stores the undo_payload as JSON on the row.
     */
    public function test_register_persists_payload(): void {
        $this->resetAfterTest();
        set_config('agent_undo_window_seconds', 120, 'local_ai_manager');
        $call = $this->make_call();

        $clock = \core\di::get(\core\clock::class);
        $manager = new undo_manager(new tool_registry(), $clock);
        $manager->register($call, ['tool' => 'course_delete', 'args' => ['courseid' => 42]]);

        $reloaded = new tool_call((int) $call->get('id'));
        $decoded = json_decode((string) $reloaded->get('undo_payload'), true);
        $this->assertSame('course_delete', $decoded['tool']);
        $this->assertSame(42, $decoded['args']['courseid']);
    }

    /**
     * register() is a no-op when the payload has no tool.
     */
    public function test_register_ignores_empty_payload(): void {
        $this->resetAfterTest();
        $call = $this->make_call();
        $manager = new undo_manager(new tool_registry(), \core\di::get(\core\clock::class));
        $manager->register($call, []);

        $reloaded = new tool_call((int) $call->get('id'));
        $this->assertNull($reloaded->get('undo_payload'));
    }

    /**
     * can_undo returns false when the window size is zero.
     */
    public function test_can_undo_false_when_window_disabled(): void {
        $this->resetAfterTest();
        set_config('agent_undo_window_seconds', 0, 'local_ai_manager');
        $call = $this->make_call();
        $manager = new undo_manager(new tool_registry(), \core\di::get(\core\clock::class));
        $manager->register($call, ['tool' => 'course_delete', 'args' => []]);
        $call = new tool_call((int) $call->get('id'));

        $this->assertFalse($manager->can_undo($call));
    }

    /**
     * can_undo is false once the window has elapsed.
     */
    public function test_can_undo_false_after_window(): void {
        $this->resetAfterTest();
        set_config('agent_undo_window_seconds', 60, 'local_ai_manager');
        $call = $this->make_call(age: 120);
        $manager = new undo_manager(new tool_registry(), \core\di::get(\core\clock::class));
        $manager->register($call, ['tool' => 'course_delete', 'args' => []]);
        $call = new tool_call((int) $call->get('id'));

        $this->assertFalse($manager->can_undo($call));
    }

    /**
     * can_undo is true inside the window and with a payload present.
     */
    public function test_can_undo_true_in_window(): void {
        $this->resetAfterTest();
        set_config('agent_undo_window_seconds', 120, 'local_ai_manager');
        $call = $this->make_call(age: 10);
        $manager = new undo_manager(new tool_registry(), \core\di::get(\core\clock::class));
        $manager->register($call, ['tool' => 'course_delete', 'args' => []]);
        $call = new tool_call((int) $call->get('id'));

        $this->assertTrue($manager->can_undo($call));
    }

    /**
     * can_undo returns false once undone_at is set.
     */
    public function test_can_undo_false_when_already_undone(): void {
        $this->resetAfterTest();
        set_config('agent_undo_window_seconds', 120, 'local_ai_manager');
        $call = $this->make_call(age: 5);
        $manager = new undo_manager(new tool_registry(), \core\di::get(\core\clock::class));
        $manager->register($call, ['tool' => 'course_delete', 'args' => []]);
        $call = new tool_call((int) $call->get('id'));
        $call->set('undone_at', time());
        $call->save();

        $this->assertFalse($manager->can_undo($call));
    }
}

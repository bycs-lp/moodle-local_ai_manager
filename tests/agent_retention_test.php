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
 * Tests for the agent retention cleanup task and privacy provider extensions (MBS-10761 Baustein 9).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager;

use local_ai_manager\local\data_wiper;
use local_ai_manager\task\agent_run_cleanup;

/**
 * @covers \local_ai_manager\task\agent_run_cleanup
 * @covers \local_ai_manager\local\data_wiper::anonymize_agent_data_for_user
 * @covers \local_ai_manager\local\data_wiper::delete_trust_prefs_for_user
 */
final class agent_retention_test extends \advanced_testcase {

    /**
     * Insert a synthetic agent run row with companion tool_call.
     *
     * @param int $userid
     * @param int $timecreated
     * @return int runid
     */
    private function insert_run(int $userid, int $timecreated): int {
        global $DB;
        $runid = $DB->insert_record('local_ai_manager_agent_runs', (object) [
            'conversationid' => 0,
            'userid' => $userid,
            'contextid' => SYSCONTEXTID,
            'component' => 'block_ai_chat',
            'mode' => 'native',
            'connector' => 'chatgpt',
            'status' => 'completed',
            'user_prompt' => 'List my courses',
            'started' => $timecreated,
            'finished' => $timecreated + 1,
            'timecreated' => $timecreated,
            'timemodified' => $timecreated + 1,
        ]);
        $DB->insert_record('local_ai_manager_tool_calls', (object) [
            'runid' => $runid,
            'callindex' => 0,
            'toolname' => 'course_list',
            'args_json' => '{}',
            'args_hash' => str_repeat('a', 64),
            'approval_state' => 'auto',
            'timecreated' => $timecreated,
            'timemodified' => $timecreated,
        ]);
        return $runid;
    }

    /**
     * Retention deletes runs older than configured days, keeps recent runs.
     */
    public function test_cleanup_deletes_old_runs(): void {
        global $DB;
        $this->resetAfterTest();

        $now = (new \DateTimeImmutable('2026-06-01 12:00:00'))->getTimestamp();
        $this->mock_clock_with_frozen($now);

        set_config('agent_run_retention_days', 30, 'local_ai_manager');

        $oldrun = $this->insert_run(7, $now - (60 * DAYSECS));
        $freshrun = $this->insert_run(7, $now - (5 * DAYSECS));

        (new agent_run_cleanup())->execute();

        $this->assertFalse($DB->record_exists('local_ai_manager_agent_runs', ['id' => $oldrun]));
        $this->assertFalse($DB->record_exists('local_ai_manager_tool_calls', ['runid' => $oldrun]));
        $this->assertTrue($DB->record_exists('local_ai_manager_agent_runs', ['id' => $freshrun]));
    }

    /**
     * Retention setting of 0 keeps everything.
     */
    public function test_cleanup_zero_days_disables_deletion(): void {
        global $DB;
        $this->resetAfterTest();

        $now = (new \DateTimeImmutable('2026-06-01 12:00:00'))->getTimestamp();
        $this->mock_clock_with_frozen($now);

        set_config('agent_run_retention_days', 0, 'local_ai_manager');
        $runid = $this->insert_run(7, $now - (500 * DAYSECS));

        (new agent_run_cleanup())->execute();

        $this->assertTrue($DB->record_exists('local_ai_manager_agent_runs', ['id' => $runid]));
    }

    /**
     * Expired trust preferences and file-extract cache entries are purged regardless of retention.
     */
    public function test_cleanup_purges_expired_trust_and_cache(): void {
        global $DB;
        $this->resetAfterTest();

        $now = (new \DateTimeImmutable('2026-06-01 12:00:00'))->getTimestamp();
        $this->mock_clock_with_frozen($now);

        $expiredtrust = $DB->insert_record('local_ai_manager_trust_prefs', (object) [
            'userid' => 9,
            'toolname' => 'course_list',
            'scope' => 'session',
            'expires' => $now - 100,
            'timecreated' => $now - 1000,
            'timemodified' => $now - 1000,
        ]);
        $livetrust = $DB->insert_record('local_ai_manager_trust_prefs', (object) [
            'userid' => 9,
            'toolname' => 'course_get_info',
            'scope' => 'user',
            'expires' => 0,
            'timecreated' => $now - 1000,
            'timemodified' => $now - 1000,
        ]);
        $expiredcache = $DB->insert_record('local_ai_manager_file_extract_cache', (object) [
            'contenthash' => str_repeat('b', 64),
            'mechanism' => 'converter',
            'extracted_text' => 'old',
            'expires' => $now - 50,
            'timecreated' => $now - 1000,
            'timemodified' => $now - 1000,
        ]);

        (new agent_run_cleanup())->execute();

        $this->assertFalse($DB->record_exists('local_ai_manager_trust_prefs', ['id' => $expiredtrust]));
        $this->assertTrue($DB->record_exists('local_ai_manager_trust_prefs', ['id' => $livetrust]));
        $this->assertFalse($DB->record_exists('local_ai_manager_file_extract_cache', ['id' => $expiredcache]));
    }

    /**
     * Anonymization wipes personal fields on agent runs and tool calls.
     */
    public function test_anonymize_agent_data_for_user(): void {
        global $DB;
        $this->resetAfterTest();

        $now = (new \DateTimeImmutable('2026-06-01 12:00:00'))->getTimestamp();
        $this->mock_clock_with_frozen($now);
        $runid = $this->insert_run(42, $now);

        (new data_wiper())->anonymize_agent_data_for_user(42);

        $run = $DB->get_record('local_ai_manager_agent_runs', ['id' => $runid], '*', MUST_EXIST);
        $this->assertSame(0, (int) $run->userid);
        $this->assertSame(data_wiper::ANONYMIZE_STRING, $run->user_prompt);

        $call = $DB->get_record('local_ai_manager_tool_calls', ['runid' => $runid], '*', MUST_EXIST);
        $this->assertSame(data_wiper::ANONYMIZE_STRING, $call->args_json);
        $this->assertNull($call->result_json);
    }

    /**
     * Trust preferences for a user are deleted outright.
     */
    public function test_delete_trust_prefs_for_user(): void {
        global $DB;
        $this->resetAfterTest();

        $now = time();
        $DB->insert_record('local_ai_manager_trust_prefs', (object) [
            'userid' => 11,
            'toolname' => 'course_list',
            'scope' => 'user',
            'expires' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->insert_record('local_ai_manager_trust_prefs', (object) [
            'userid' => 12,
            'toolname' => 'course_list',
            'scope' => 'user',
            'expires' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        (new data_wiper())->delete_trust_prefs_for_user(11);

        $this->assertFalse($DB->record_exists('local_ai_manager_trust_prefs', ['userid' => 11]));
        $this->assertTrue($DB->record_exists('local_ai_manager_trust_prefs', ['userid' => 12]));
    }
}

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
 * Custom Behat steps for local_ai_manager (MBS-10761).
 *
 * @package    local_ai_manager
 * @category   test
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Steps definitions for the local_ai_manager Behat tests.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_ai_manager extends behat_base {

    /**
     * Seed an agent run that was finished the given number of days ago.
     *
     * @Given /^an agent run by "(?P<username>(?:[^"]|\\")*)" was finished "(?P<days>\d+)" days ago$/
     * @param string $username Owning username.
     * @param int $days Age of the run in days.
     */
    public function an_agent_run_by_was_finished_days_ago(string $username, int $days): void {
        global $DB;
        $userid = (int) $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $ts = time() - ($days * DAYSECS);
        $runid = $DB->insert_record('local_ai_manager_agent_runs', (object) [
            'conversationid' => 0,
            'userid' => $userid,
            'contextid' => SYSCONTEXTID,
            'component' => 'block_ai_chat',
            'mode' => 'native',
            'connector' => 'chatgpt',
            'status' => 'completed',
            'user_prompt' => 'aged prompt',
            'entity_context' => null,
            'iterations' => 1,
            'rejections' => 0,
            'error_message' => null,
            'started' => $ts,
            'finished' => $ts,
            'timecreated' => $ts,
            'timemodified' => $ts,
            'usermodified' => $userid,
        ]);
        $DB->insert_record('local_ai_manager_tool_calls', (object) [
            'runid' => $runid,
            'callindex' => 0,
            'toolname' => 'course_list',
            'args_json' => '{}',
            'args_hash' => str_repeat('a', 64),
            'approval_state' => 'auto',
            'approved_by' => null,
            'result_json' => null,
            'error_message' => null,
            'undo_payload' => null,
            'timecreated' => $ts,
            'timemodified' => $ts,
            'usermodified' => $userid,
        ]);
    }

    /**
     * Assert the number of agent run rows in the database.
     *
     * @Then /^there should be "(?P<count>\d+)" agent runs in the database$/
     * @param int $count Expected row count.
     */
    public function there_should_be_agent_runs_in_the_database(int $count): void {
        global $DB;
        $actual = (int) $DB->count_records('local_ai_manager_agent_runs');
        if ($actual !== (int) $count) {
            throw new \Behat\Mink\Exception\ExpectationException(
                "Expected {$count} agent_runs rows, found {$actual}.",
                $this->getSession()
            );
        }
    }

    /**
     * Assert the number of trust pref rows in the database.
     *
     * @Then /^there should be "(?P<count>\d+)" trust prefs in the database$/
     * @param int $count Expected row count.
     */
    public function there_should_be_trust_prefs_in_the_database(int $count): void {
        global $DB;
        $actual = (int) $DB->count_records('local_ai_manager_trust_prefs');
        if ($actual !== (int) $count) {
            throw new \Behat\Mink\Exception\ExpectationException(
                "Expected {$count} trust_prefs rows, found {$actual}.",
                $this->getSession()
            );
        }
    }
}

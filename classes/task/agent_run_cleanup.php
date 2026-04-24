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
 * Scheduled retention task for agent runs (MBS-10761 Baustein 9).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\task;

use core\task\scheduled_task;

/**
 * Deletes old agent_runs + their tool_calls and expired trust_prefs /
 * file_extract_cache rows.
 *
 * The retention window is controlled by the admin setting
 * `local_ai_manager / agent_run_retention_days` (default 90 days). A value of 0
 * disables automatic deletion.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class agent_run_cleanup extends scheduled_task {

    #[\Override]
    public function get_name(): string {
        return get_string('task_agent_run_cleanup', 'local_ai_manager');
    }

    #[\Override]
    public function execute(): void {
        global $DB;

        $clock = \core\di::get(\core\clock::class);
        $now = $clock->now()->getTimestamp();

        // Expired trust preferences — always purge regardless of retention setting.
        $DB->delete_records_select(
            'local_ai_manager_trust_prefs',
            'expires > 0 AND expires < :now',
            ['now' => $now],
        );

        // Expired file-extraction cache entries.
        $DB->delete_records_select(
            'local_ai_manager_file_extract_cache',
            'expires > 0 AND expires < :now',
            ['now' => $now],
        );

        $retentiondays = (int) get_config('local_ai_manager', 'agent_run_retention_days');
        if ($retentiondays <= 0) {
            // Retention disabled — keep runs forever.
            return;
        }
        $cutoff = $now - ($retentiondays * DAYSECS);

        $oldrunids = $DB->get_fieldset_select(
            'local_ai_manager_agent_runs',
            'id',
            'timecreated < :cutoff',
            ['cutoff' => $cutoff],
        );
        if (empty($oldrunids)) {
            return;
        }
        [$insql, $inparams] = $DB->get_in_or_equal($oldrunids, SQL_PARAMS_NAMED, 'rid');
        $DB->delete_records_select('local_ai_manager_tool_calls', "runid $insql", $inparams);
        $DB->delete_records_select('local_ai_manager_agent_runs', "id $insql", $inparams);
    }
}

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

namespace local_ai_manager\task;

/**
 * Cleanup task for cleaning up broken tasks which left locks and entries behind in redis and the database.
 *
 * Care: If all scheduled task locks already have been burned, this task will not run, so you will have to fix this by
 * running cli/cleanup_broken_task_entries.php to unlock the tasks again.
 *
 * @package   local_ai_manager
 * @copyright 2024 ISB Bayern
 * @author    Philipp Memmel
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class data_wiper extends \core\task\scheduled_task {
    /**
     * Returns the name of the task.
     *
     * @return string the name of the task
     */
    public function get_name(): string {
        return get_string('cleanuprequestlogtask', 'local_ai_manager');
    }

    /**
     * Execute the cleanup.
     */
    public function execute(): void {
        if (empty(get_config('local_ai_manager', 'enablecleanuprequestlogtask'))) {
            return;
        }
        $datawiper = new \local_ai_manager\local\data_wiper();
        $datawiper->cleanup_request_log_data();
    }
}

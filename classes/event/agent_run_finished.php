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
 * Event: an agent run has finished (MBS-10761 Baustein 9).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\event;

/**
 * Fired by the orchestrator once a run reaches a terminal status
 * (completed, failed or aborted).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class agent_run_finished extends \core\event\base {

    #[\Override]
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_ai_manager_agent_runs';
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event_agent_run_finished', 'local_ai_manager');
    }

    /**
     * Localised event description.
     *
     * @return string
     */
    public function get_description(): string {
        return get_string('event_agent_run_finished_desc', 'local_ai_manager', (object) [
            'runid' => $this->objectid,
            'userid' => $this->userid,
            'status' => $this->other['status'] ?? '',
            'iterations' => $this->other['iterations'] ?? 0,
        ]);
    }
}

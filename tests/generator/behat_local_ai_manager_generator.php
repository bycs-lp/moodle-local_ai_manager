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
 * Behat data generator for local_ai_manager (MBS-10761).
 *
 * @package    local_ai_manager
 * @category   test
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_ai_manager_generator extends behat_generator_base {

    /**
     * Get a list of entities that can be created through Behat "Given the following ... exist:" steps.
     *
     * @return array entity name => generator info.
     */
    protected function get_creatable_entities(): array {
        return [
            'agent runs' => [
                'singular' => 'agent run',
                'datagenerator' => 'agent_run',
                'required' => [],
                'switchids' => ['user' => 'userid'],
            ],
            'tool calls' => [
                'singular' => 'tool call',
                'datagenerator' => 'tool_call',
                'required' => ['runid'],
            ],
            'trust prefs' => [
                'singular' => 'trust pref',
                'datagenerator' => 'trust_pref',
                'required' => [],
                'switchids' => ['user' => 'userid'],
            ],
            'tool overrides' => [
                'singular' => 'tool override',
                'datagenerator' => 'tool_override',
                'required' => ['toolname'],
            ],
        ];
    }

    /**
     * Resolve a username to a user id for switchids.
     *
     * @param string $username The username to resolve.
     * @return int The user id.
     */
    protected function get_user_id(string $username): int {
        global $DB;
        return $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
    }

    /**
     * Create a tool_override row (cannot reuse component generator, written inline).
     *
     * @param array $data Row data from the Behat table.
     */
    protected function process_tool_override(array $data): void {
        global $DB, $USER;
        $now = time();
        $record = (object) array_merge([
            'tenantid' => null,
            'toolname' => '',
            'category_override' => null,
            'description_override' => null,
            'glossary_json' => null,
            'enabled' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => $USER->id,
        ], $data);
        $DB->insert_record('local_ai_manager_tool_overrides', $record);
    }
}

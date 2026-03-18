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
 * Upgrade helper functions for local_ai_manager.
 *
 * @package   local_ai_manager
 * @copyright 2026 ISB Bayern
 * @author    Thomas Schönlein
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Cleans up legacy Azure data stored in connector instance custom fields.
 *
 * The former Azure implementation stored resource metadata in customfield3-5.
 * Only customfield3 of Gemini instances remains functionally relevant.
 *
 * @return void
 */
function local_ai_manager_cleanup_legacy_azure_instance_data(): void {
    global $DB;

    $DB->set_field_select('local_ai_manager_instance', 'customfield4', null, 'customfield4 IS NOT NULL');
    $DB->set_field_select('local_ai_manager_instance', 'customfield5', null, 'customfield5 IS NOT NULL');

    [$insql, $params] = $DB->get_in_or_equal(['chatgpt', 'dalle', 'openaitts'], SQL_PARAMS_NAMED);
    $DB->set_field_select(
        'local_ai_manager_instance',
        'customfield3',
        null,
        "connector $insql AND customfield3 IS NOT NULL",
        $params
    );
}

<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Environment checks for local_ai_manager
 *
 * @package     local_ai_manager
 * @copyright   2026, ISB Bayern
 * @author      Johannes Funk, <johannesfunk@outlook.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Check that the currently used tenant identifiers are valid.
 *
 * @param environment_results $result
 * @return environment_results updated results object
 */
function local_ai_manager_check_valid_tenants(environment_results $result): environment_results {
    global $DB;

    if (!$DB->get_manager()->table_exists('local_ai_manager_config')) {
        $result->setStatus(true);
        return $result;
    }

    $identifiers = $DB->get_fieldset_sql(
        "SELECT DISTINCT tenant
           FROM {local_ai_manager_config}
          WHERE tenant IS NOT NULL
            AND tenant <> ''"
    );

    foreach ($identifiers as $identifier) {
        if (!\local_ai_manager\local\tenant::is_valid_identifier($identifier)) {
            $result->setInfo('Some currently used tenant identifiers are invalid.');
            $result->setStatus(false);
            return $result;
        }
    }
    $result->setInfo('All currently used tenants valid');
    $result->setStatus(true);
    return $result;
}

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

namespace local_ai_manager\check;
use core\check\check;
use core\check\result;
use core\output\html_writer;

/**
 * Check whether tenant identifiers stored in the configured user column are valid.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Johannes Funk
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tenantcolumn_identifiers_valid extends check {
    /**
     * Return the check result for tenant identifier validity.
     *
     * @return result The check result object.
     */
    public function get_result(): result {
        $invalidcolumns = $this->find_invalid_columns();
        if (empty($invalidcolumns)) {
            $status = result::OK;
            $summary = get_string('check_usercolums_valid_summary', 'local_ai_manager');
            $details = '';
        } else {
            $status = result::ERROR;
            $summary  = get_string('check_usercolumns_invalid_summary', 'local_ai_manager');
            $details = $this->render_details($invalidcolumns);
        }
        return new result($status, $summary, $details);
    }

    /**
     * Find all invalid identifier values from the configured tenant user column.
     *
     * @return array List of invalid identifiers.
     */
    public function find_invalid_columns(): array {
        global $DB;
        if (!$DB->get_manager()->table_exists('local_ai_manager_config')) {
            return [];
        }

        $tenantfield = get_config('local_ai_manager', 'tenantcolumn');
        $identifiers = $DB->get_fieldset_sql(
            "SELECT DISTINCT $tenantfield
           FROM {user}
          WHERE $tenantfield IS NOT NULL
            AND $tenantfield <> ''"
        );

        $invalidtenants = [];
        foreach ($identifiers as $identifier) {
            if (!\local_ai_manager\local\tenant::is_valid_identifier($identifier)) {
                $invalidtenants[] = $identifier;
            }
        }
        return $invalidtenants;
    }

    /**
     * Render details listing invalid identifiers.
     *
     * @param array $invalididentifiers Invalid identifier values.
     * @return string HTML string with details.
     */
    public function render_details(array $invalididentifiers): string {
        $output = "";
        $output .= get_string('check_usercolumns_invalid_details', 'local_ai_manager');

        if (count($invalididentifiers) < 10) {
            $list = implode(", ", $invalididentifiers);
        } else {
            $shortenedlist = array_splice($invalididentifiers, 0, 10);
            $list = implode(", ", $shortenedlist);
            $list .= " + " . (count($shortenedlist) - 10);
        }
        $output .= html_writer::div('Invalid identifiers: ' . html_writer::span(s($list), 'font-italic'), 'my-2');
        return $output;
    }
}

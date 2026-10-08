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
 * Functions to link into the main Moodle API
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Johannes Funk
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Register plugin status checks with Moodle's Check API.
 *
 * @return array array of status checks to be executed
 */
function local_ai_manager_status_checks(): array {
    return [
        new \local_ai_manager\check\tenantcolumn_identifiers_valid(),
    ];
}

/**
 * Resets the current tenant before each external function is being executed.
 *
 * Web service batch requests (for example via lib/ajax/service.php) execute several external functions in the same PHP process.
 * An external function may set another tenant than the one of the current user (after checking the access). Resetting the
 * current tenant here ensures that such a tenant does not leak into the following external functions of the same request.
 *
 * @param stdClass $externalfunctioninfo the information about the external function which is about to be executed
 * @param array $params the parameters of the external function
 * @return false always false, because this callback never overrides the execution of the external function
 */
function local_ai_manager_override_webservice_execution(stdClass $externalfunctioninfo, array $params): bool {
    \core\di::get(\local_ai_manager\local\tenant_factory::class)->reset();
    return false;
}

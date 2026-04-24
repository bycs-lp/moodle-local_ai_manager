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
 * External function: persist a tool-trust preference (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_ai_manager\agent\trust_resolver;
use local_ai_manager\local\agent\entity\trust_pref;

/**
 * Trust a tool for the current session, user or tenant.
 *
 * Global (tenant-wide) trust additionally requires
 * {@code local/ai_manager:configuretrust}.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class agent_trust_tool extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'toolname' => new external_value(PARAM_ALPHANUMEXT, 'Tool name'),
            'scope' => new external_value(PARAM_ALPHA, 'session | user | global'),
            'tenantid' => new external_value(PARAM_INT, 'Tenant id (optional)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Execute.
     *
     * @param string $toolname
     * @param string $scope
     * @param int $tenantid
     * @return array
     */
    public static function execute(string $toolname, string $scope, int $tenantid = 0): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'toolname' => $toolname,
            'scope' => $scope,
            'tenantid' => $tenantid,
        ]);

        $context = \core\context\system::instance();
        self::validate_context($context);
        require_capability('local/ai_manager:use', $context);

        $allowedscopes = [trust_pref::SCOPE_SESSION, trust_pref::SCOPE_USER, trust_pref::SCOPE_GLOBAL];
        if (!in_array($params['scope'], $allowedscopes, true)) {
            throw new \invalid_parameter_exception('Invalid scope');
        }

        if ($params['scope'] === trust_pref::SCOPE_GLOBAL) {
            require_capability('local/ai_manager:configuretrust', $context);
        }

        $sessionkey = $params['scope'] === trust_pref::SCOPE_SESSION ? sesskey() : null;
        $resolver = new trust_resolver();
        $pref = $resolver->set_trust(
            (int) $USER->id,
            $params['toolname'],
            $params['scope'],
            $sessionkey,
            $params['tenantid'] > 0 ? $params['tenantid'] : null,
        );

        return [
            'status' => 'trusted',
            'scope' => $params['scope'],
            'id' => (int) $pref->get('id'),
        ];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'trusted'),
            'scope' => new external_value(PARAM_ALPHA, 'Scope applied'),
            'id' => new external_value(PARAM_INT, 'Trust-pref row id'),
        ]);
    }
}

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

namespace local_ai_manager\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_ai_manager\ai_manager_utils;

/**
 * Web service to submit a query to an AI tool.
 *
 * @package    local_ai_manager
 * @copyright  ISB Bayern, 2024
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_ai_config extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'tenant' => new external_value(
                    PARAM_TEXT,
                    'The tenant identifier, only useful to add if the user has access to multiple tenants',
                    VALUE_DEFAULT,
                    null
                ),
                'contextid' => new external_value(
                    PARAM_INT,
                    'The id of the current context',
                    VALUE_DEFAULT,
                    0
                ),
                'purposes' => new external_multiple_structure(
                    new external_value(
                        PARAM_ALPHANUM,
                        'The purpose string',
                        VALUE_REQUIRED,
                    ),
                    'The purposes to retrieve the config for',
                    VALUE_DEFAULT,
                    null,
                ),
            ]
        );
    }

    /**
     * Retrieve the purpose config.
     *
     * @param ?string $tenant the tenant to use, only useful for accounts which can access/manage more than their own tenant
     * @param int $contextid the contextid for which the config should be retrieved, if 0 the system context is used
     * @param ?array $purposes if set, only the config for the given purposes
     * @return array associative array containing the result of the request
     */
    public static function execute(?string $tenant = null, int $contextid = 0, ?array $purposes = null): array {
        global $USER;
        [
            'tenant' => $tenant,
            'contextid' => $contextid,
            'purposes' => $purposes,
        ] = self::validate_parameters(
            self::execute_parameters(),
            [
                'tenant' => $tenant,
                'contextid' => $contextid,
                'purposes' => $purposes,
            ]
        );
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/ai_manager:use', $context);
        return ai_manager_utils::get_ai_config($USER, $contextid, $tenant, $purposes);
    }

    /**
     * Describes the return structure of the service.
     *
     * @return external_single_structure the return structure
     */
    public static function execute_returns(): external_single_structure {
        $singlestructuredefinition = [];
        $singlestructuredefinition['availability'] = new external_single_structure(
            [
                'available' => new external_value(
                    PARAM_ALPHANUM,
                    'If/How the frontend plugin should show itself is available, can be "available", '
                    . '"hidden" or "disabled"',
                    VALUE_REQUIRED
                ),
                'errormessage' => new external_value(
                    PARAM_RAW,
                    'The error message that should be displayed to the user if the AI is not "available"',
                    VALUE_REQUIRED
                ),
            ]
        );
        $singlestructuredefinition['purposes'] = new external_multiple_structure(
            new external_single_structure(
                [
                    'purpose' => new external_value(PARAM_ALPHANUM, 'The purpose name', VALUE_REQUIRED),
                    'available' => new external_value(
                        PARAM_ALPHANUM,
                        'if the purpose is available, can be "available", "hidden" or "disabled"',
                        VALUE_REQUIRED
                    ),
                    'errormessage' => new external_value(
                        PARAM_TEXT,
                        'The error message that should be displayed to the user if the purpose is not "available"',
                        VALUE_REQUIRED
                    ),
                ]
            )
        );

        return new external_single_structure(
            $singlestructuredefinition,
            'Object containing the tools configured for each purpose'
        );
    }
}

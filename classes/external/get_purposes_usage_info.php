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
 * External function to provide the purpose usage info.
 *
 * @package    local_ai_manager
 * @copyright  2025 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_purposes_usage_info extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Retrieve the purpose usage info object.
     *
     * @return array associative array containing the result of the request
     */
    public static function execute(): array {
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/ai_manager:use', $context);
        return ai_manager_utils::get_purposes_usage_info();
    }

    /**
     * Describes the return structure of the service.
     *
     * @return external_single_structure the return structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'purposes' => new external_multiple_structure(
                new external_single_structure(
                    [
                        'purposename' => new external_value(PARAM_TEXT, 'Name of the purpose', VALUE_REQUIRED),
                        'purposedisplayname' => new external_value(
                            PARAM_TEXT,
                            'Display name of the purpose',
                            VALUE_REQUIRED
                        ),
                        'purposedescription' => new external_value(
                            PARAM_RAW,
                            'Description of the purpose',
                            VALUE_REQUIRED
                        ),
                        'components' => new external_multiple_structure(
                            new external_single_structure(
                                [
                                    'component' => new external_value(
                                        PARAM_TEXT,
                                        'Name of the component',
                                        VALUE_REQUIRED
                                    ),
                                    'componentdisplayname' => new external_value(
                                        PARAM_TEXT,
                                        'Display name of the component',
                                        VALUE_REQUIRED
                                    ),
                                    'placedescriptions' => new external_multiple_structure(
                                        new external_single_structure(
                                            [
                                                'description' => new external_value(
                                                    PARAM_RAW,
                                                    'Description of the place',
                                                    VALUE_REQUIRED
                                                ),
                                            ],
                                            'Place description object containing only the description of the place'
                                        ),
                                        'place descriptions',
                                        VALUE_REQUIRED
                                    ),
                                ]
                            ),
                            'Components that are using this purpose',
                            VALUE_OPTIONAL
                        ),
                    ],
                    'Purpose usage information objects',
                    VALUE_REQUIRED
                ),
                'Purposes usage information object',
                VALUE_REQUIRED
            ),
        ]);
    }
}

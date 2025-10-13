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
 * Web service to retrieve all prompts of a user for a given context and its subcontexts.
 *
 * @package    local_ai_manager
 * @copyright  2024 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_prompts extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'contextid' => new external_value(
                    PARAM_INT,
                    'The context id of the context from which the request is being done',
                    VALUE_REQUIRED
                ),
                'userid' => new external_value(PARAM_INT, 'The user id to retrieve the prompts for', VALUE_REQUIRED),
                'time' => new external_value(
                    PARAM_INT,
                    'The unix time stamp since when prompts should be retrieved',
                    VALUE_DEFAULT,
                    0
                ),
            ]
        );
    }

    /**
     * Execute the service.
     *
     * @param int $contextid The context in which the prompts should be retrieved, should either be a course context or below or a
     *  tenant context
     * @param int $userid The id of the user to retrieve the prompts for
     *
     * @return array associative array containing the result of the request
     */
    public static function execute(int $contextid, int $userid, int $time): array {
        [
            'contextid' => $contextid,
            'userid' => $userid,
            'time' => $time,
        ] = self::validate_parameters(
            self::execute_parameters(),
            [
                'contextid' => $contextid,
                'userid' => $userid,
                'time' => $time,
            ]
        );

        $context = \context::instance_by_id($contextid);
        self::validate_context($context);
        if ($context->contextlevel === CONTEXT_COURSE) {
            require_capability('local/ai_manager:viewprompts', $context);
        } else {
            require_capability('local/ai_manager:viewtenantprompts', $context);
        }

        try {
            $return = ['code' => 200, 'string' => 'ok',
                'result' => ai_manager_utils::get_structured_entries_by_context($contextid, $userid, $time)];
        } catch (\Exception $e) {
            $return = ['code' => 500, 'string' => 'error', 'result' => $e->getMessage()];
        }

        return $return;
    }

    /**
     * Describes the return structure of the service.
     *
     * @return external_single_structure the return structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(
            [
                'code' => new external_value(PARAM_INT, 'Return code of process.'),
                'string' => new external_value(PARAM_TEXT, 'Return string of process.'),
                'result' => new external_multiple_structure(
                    new external_single_structure([
                        'contextid' => new external_value(
                            PARAM_INT,
                            'The context id of the context',
                            VALUE_REQUIRED
                        ),
                        'contextdisplayname' => new external_value(
                            PARAM_TEXT,
                            'The display name of the object the context relates to',
                            VALUE_REQUIRED
                        ),
                        'prompts' => new external_multiple_structure(
                            new external_single_structure(
                                [
                                    'sequencenumber' => new external_value(
                                        PARAM_INT,
                                        'The sequence number of the prompt',
                                        VALUE_REQUIRED
                                    ),
                                    'prompt' => new external_value(
                                        PARAM_RAW,
                                        'The prompt',
                                        VALUE_REQUIRED
                                    ),
                                    'promptshortened' => new external_value(
                                        PARAM_TEXT,
                                        'Shortened version of the prompt',
                                        VALUE_REQUIRED
                                    ),
                                    'promptcompletion' => new external_value(
                                        PARAM_RAW,
                                        'The prompt completion',
                                        VALUE_REQUIRED
                                    ),
                                    'promptcompletionshortened' => new external_value(
                                        PARAM_TEXT,
                                        'Shortened version of the prompt completion',
                                        VALUE_REQUIRED
                                    ),
                                    'firstprompt' => new external_value(
                                        PARAM_BOOL,
                                        'If this is the first prompt in this context prompt list',
                                        VALUE_REQUIRED
                                    ),
                                    'date' => new external_value(
                                        PARAM_INT,
                                        'The unix time stamp of the logged AI request',
                                        VALUE_REQUIRED
                                    ),
                                ],
                            )
                        ),
                        'promptscount' => new external_value(
                            PARAM_INT,
                            'The number of prompts in this context',
                            VALUE_REQUIRED
                        ),
                        'viewpromptsdates' => new external_value(
                            PARAM_BOOL,
                            'If the data object contains prompts dates',
                            VALUE_REQUIRED
                        ),
                    ], 'The prompt object'),
                ),
            ],
            'The result object of the get_prompts service'
        );
    }
}

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

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_ai_manager\local\model;

/**
 * External function to delete an AI model definition.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class delete_model extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'modelid' => new external_value(PARAM_INT, 'The model id to delete'),
        ]);
    }

    /**
     * Delete a model and all its connector assignments.
     *
     * @param int $modelid The model id
     * @return array Result with success status
     */
    public static function execute(int $modelid): array {
        ['modelid' => $modelid] = self::validate_parameters(
            self::execute_parameters(),
            ['modelid' => $modelid]
        );

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/ai_manager:managemodels', $context);

        $modelobj = new model($modelid);
        if (!$modelobj->record_exists()) {
            throw new \moodle_exception('exception_modelnotfound', 'local_ai_manager', '', $modelid);
        }

        $modelobj->delete();

        return ['success' => true];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the deletion was successful'),
        ]);
    }
}

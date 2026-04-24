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
 * External function: reject a pending tool call (MBS-10761).
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
use local_ai_manager\local\agent\entity\tool_call;

/**
 * Mark a pending tool call as rejected.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class agent_reject_tool_call extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'runid' => new external_value(PARAM_INT, 'Agent run id'),
            'callindex' => new external_value(PARAM_INT, 'Tool call index within the run'),
            'reason' => new external_value(PARAM_TEXT, 'Optional rejection reason', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Execute.
     *
     * @param int $runid
     * @param int $callindex
     * @param string $reason
     * @return array
     */
    public static function execute(int $runid, int $callindex, string $reason = ''): array {
        global $USER, $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'runid' => $runid,
            'callindex' => $callindex,
            'reason' => $reason,
        ]);

        $context = \core\context\system::instance();
        self::validate_context($context);
        require_capability('local/ai_manager:use', $context);

        $callrecord = $DB->get_record(
            'local_ai_manager_tool_calls',
            ['runid' => $params['runid'], 'callindex' => $params['callindex']],
            '*',
            MUST_EXIST,
        );
        $call = new tool_call(0, $callrecord);

        if ($call->get('approval_state') !== tool_call::APPROVAL_AWAITING) {
            throw new \moodle_exception('agent_call_not_awaiting', 'local_ai_manager');
        }

        $clock = \core\di::get(\core\clock::class);
        $call->set('approval_state', tool_call::APPROVAL_REJECTED);
        $call->set('approved_by', (int) $USER->id);
        $call->set('approved_at', $clock->now()->getTimestamp());
        if ($params['reason'] !== '') {
            $call->set('error_message', $params['reason']);
        }
        $call->save();

        return [
            'status' => 'rejected',
            'runid' => $params['runid'],
            'callindex' => $params['callindex'],
        ];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'rejected'),
            'runid' => new external_value(PARAM_INT, 'Run id'),
            'callindex' => new external_value(PARAM_INT, 'Call index'),
        ]);
    }
}

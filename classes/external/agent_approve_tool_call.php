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
 * External function: approve a pending tool call (MBS-10761).
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
use local_ai_manager\agent\approval_token;
use local_ai_manager\agent\exception\invalid_token_exception;
use local_ai_manager\local\agent\entity\tool_call;

/**
 * Validate an approval token and mark the tool call as approved.
 *
 * The call is NOT executed synchronously — the orchestrator picks up the
 * approved state on the next iteration. This keeps the AJAX cycle short
 * and allows the client to poll the run status independently.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class agent_approve_tool_call extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'runid' => new external_value(PARAM_INT, 'Agent run id'),
            'callindex' => new external_value(PARAM_INT, 'Tool call index within the run'),
            'token' => new external_value(PARAM_RAW, 'HMAC approval token'),
        ]);
    }

    /**
     * Execute.
     *
     * @param int $runid
     * @param int $callindex
     * @param string $token
     * @return array
     */
    public static function execute(int $runid, int $callindex, string $token): array {
        global $USER, $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'runid' => $runid,
            'callindex' => $callindex,
            'token' => $token,
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

        // Re-resolve to guard against a stale $callrecord.
        $call = new tool_call(0, $callrecord);

        if ($call->get('approval_state') !== tool_call::APPROVAL_AWAITING) {
            throw new \moodle_exception('agent_call_not_awaiting', 'local_ai_manager');
        }

        try {
            approval_token::instance()->verify(
                $params['token'],
                $params['runid'],
                $params['callindex'],
                (int) $USER->id,
                (string) $call->get('args_hash'),
            );
        } catch (invalid_token_exception $e) {
            if ($e->reason === 'expired') {
                $call->set('approval_state', tool_call::APPROVAL_EXPIRED);
                $call->save();
            }
            throw $e;
        }

        $clock = \core\di::get(\core\clock::class);
        $call->set('approval_state', tool_call::APPROVAL_APPROVED);
        $call->set('approved_by', (int) $USER->id);
        $call->set('approved_at', $clock->now()->getTimestamp());
        $call->save();

        return [
            'status' => 'approved',
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
            'status' => new external_value(PARAM_ALPHA, 'approved'),
            'runid' => new external_value(PARAM_INT, 'Run id'),
            'callindex' => new external_value(PARAM_INT, 'Call index'),
        ]);
    }
}

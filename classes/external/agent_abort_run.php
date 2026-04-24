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
 * External function: abort a running agent run (MBS-10761 Paket 2).
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
use local_ai_manager\local\agent\entity\agent_run;
use local_ai_manager\local\agent\entity\tool_call;

/**
 * Abort an in-flight or awaiting-approval agent run.
 *
 * Transitions the {@see agent_run} to {@see agent_run::STATUS_ABORTED_USER},
 * marks any still-pending {@see tool_call} rows as rejected, and records the
 * finish timestamp. Idempotent: runs already in a terminal state are a no-op.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class agent_abort_run extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'runid' => new external_value(PARAM_INT, 'Agent run id'),
        ]);
    }

    /**
     * Execute.
     *
     * @param int $runid
     * @return array
     */
    public static function execute(int $runid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'runid' => $runid,
        ]);

        $context = \core\context\system::instance();
        self::validate_context($context);
        require_capability('local/ai_manager:use', $context);

        $run = new agent_run($params['runid']);

        // Only the run owner (or site admin) may abort.
        if ((int) $run->get('userid') !== (int) $USER->id && !is_siteadmin()) {
            throw new \required_capability_exception($context, 'local/ai_manager:use', 'nopermissions', '');
        }

        $status = (string) $run->get('status');
        $terminal = [
            agent_run::STATUS_COMPLETED,
            agent_run::STATUS_FAILED,
            agent_run::STATUS_ABORTED_MAXITER,
            agent_run::STATUS_ABORTED_USER,
        ];
        if (in_array($status, $terminal, true)) {
            return [
                'status' => $status,
                'runid' => (int) $run->get('id'),
                'aborted' => false,
            ];
        }

        $clock = \core\di::get(\core\clock::class);
        $now = $clock->now()->getTimestamp();

        // Reject any pending tool calls so the orchestrator cannot resume them.
        $pendingcalls = tool_call::get_records([
            'runid' => (int) $run->get('id'),
            'approval_state' => tool_call::APPROVAL_AWAITING,
        ]);
        foreach ($pendingcalls as $call) {
            $call->set('approval_state', tool_call::APPROVAL_REJECTED);
            $call->set('approved_by', (int) $USER->id);
            $call->set('approved_at', $now);
            $call->set('error_message', get_string('agent_run_aborted_by_user', 'local_ai_manager'));
            $call->save();
        }

        $run->set('status', agent_run::STATUS_ABORTED_USER);
        $run->set('finished', $now);
        $run->save();

        return [
            'status' => agent_run::STATUS_ABORTED_USER,
            'runid' => (int) $run->get('id'),
            'aborted' => true,
        ];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Final status of the run'),
            'runid' => new external_value(PARAM_INT, 'Run id'),
            'aborted' => new external_value(PARAM_BOOL, 'Whether this call caused the abort'),
        ]);
    }
}

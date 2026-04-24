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
 * External function: undo a reversible tool call (MBS-10761 Paket 2).
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
use local_ai_manager\agent\execution_context;
use local_ai_manager\agent\tool_registry;
use local_ai_manager\agent\undo_manager;
use local_ai_manager\local\agent\entity\agent_run;
use local_ai_manager\local\agent\entity\tool_call;

/**
 * Invoke the inverse operation of a previously executed tool call.
 *
 * The call must be within the configured undo window and carry an undo_payload.
 * Delegates to {@see undo_manager::undo()}; the inverse tool itself does not
 * need to be part of the LLM-visible catalogue.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class agent_undo_tool_result extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'callid' => new external_value(PARAM_INT, 'Tool call id'),
        ]);
    }

    /**
     * Execute.
     *
     * @param int $callid
     * @return array
     */
    public static function execute(int $callid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'callid' => $callid,
        ]);

        $syscontext = \core\context\system::instance();
        self::validate_context($syscontext);
        require_capability('local/ai_manager:use', $syscontext);

        $call = new tool_call($params['callid']);
        $run = new agent_run((int) $call->get('runid'));

        if ((int) $run->get('userid') !== (int) $USER->id && !is_siteadmin()) {
            throw new \required_capability_exception($syscontext, 'local/ai_manager:use', 'nopermissions', '');
        }

        $runcontext = \core\context::instance_by_id((int) $run->get('contextid'));
        $clock = \core\di::get(\core\clock::class);
        $registry = \core\di::get(tool_registry::class);
        $manager = new undo_manager($registry, $clock);

        $ctx = new execution_context(
            runid: (int) $run->get('id'),
            callid: (int) $call->get('id'),
            callindex: (int) $call->get('callindex'),
            user: $USER,
            context: $runcontext,
            tenantid: $run->get('tenantid') !== null ? (int) $run->get('tenantid') : null,
            draftitemids: [],
            entity_context: [],
            clock: $clock,
        );

        $result = $manager->undo($call, $ctx);

        return [
            'ok' => $result->ok,
            'callid' => (int) $call->get('id'),
            'undone_at' => (int) $call->get('undone_at'),
            'error' => $result->error,
            'user_message' => $result->user_message,
        ];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Whether the undo succeeded'),
            'callid' => new external_value(PARAM_INT, 'Tool call id'),
            'undone_at' => new external_value(PARAM_INT, 'Unix timestamp when the call was undone'),
            'error' => new external_value(PARAM_ALPHANUMEXT, 'Error code if ok=false', VALUE_OPTIONAL, null, NULL_ALLOWED),
            'user_message' => new external_value(PARAM_TEXT, 'User-facing error message', VALUE_OPTIONAL, null, NULL_ALLOWED),
        ]);
    }
}

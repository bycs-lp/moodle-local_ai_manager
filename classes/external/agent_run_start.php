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
 * External function: run a tool-agent request (MBS-10761, Baustein 7).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_ai_manager\agent\orchestrator;
use local_ai_manager\agent\run_result;
use local_ai_manager\agent\tool_registry;

/**
 * Start (or resume) an agent run from block_ai_chat.
 *
 * Backend entry point invoked by the reactive block_ai_chat frontend. Either
 * `userprompt` (new run) or `runid` (resume) must be supplied. On success the
 * function returns the current run status including any tool calls pending
 * user approval.
 *
 * NOTE: This class does not assemble an llm_client — the concrete wiring is
 * delegated to {@see agent_runner_factory::build()} so that block_ai_chat can
 * inject its own persona/purpose-specific client while tests can inject a fake.
 * A default implementation raises `disabled` until the wiring is completed in
 * a follow-up commit of Baustein 7.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class agent_run_start extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'component' => new external_value(PARAM_COMPONENT, 'Calling component, e.g. block_ai_chat'),
            'contextid' => new external_value(PARAM_INT, 'Moodle context id the run is scoped to'),
            'userprompt' => new external_value(PARAM_RAW, 'User prompt to start a new run', VALUE_DEFAULT, ''),
            'conversationid' => new external_value(PARAM_INT, 'Optional conversation id', VALUE_DEFAULT, 0),
            'runid' => new external_value(PARAM_INT, 'Existing run id to resume', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Execute.
     *
     * @param string $component
     * @param int $contextid
     * @param string $userprompt
     * @param int $conversationid
     * @param int $runid
     * @return array
     */
    public static function execute(
        string $component,
        int $contextid,
        string $userprompt = '',
        int $conversationid = 0,
        int $runid = 0,
    ): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'component' => $component,
            'contextid' => $contextid,
            'userprompt' => $userprompt,
            'conversationid' => $conversationid,
            'runid' => $runid,
        ]);

        $context = \core\context::instance_by_id($params['contextid']);
        self::validate_context($context);
        require_capability('local/ai_manager:use', $context);

        if ($params['runid'] === 0 && trim($params['userprompt']) === '') {
            throw new \invalid_parameter_exception('Either userprompt or runid must be supplied.');
        }

        $factory = \core\di::get(agent_runner_factory::class);
        /** @var orchestrator $orchestrator */
        $orchestrator = $factory->build($params['component'], $context);

        if ($params['runid'] > 0) {
            $result = $orchestrator->resume($params['runid'], $USER, $context);
        } else {
            $result = $orchestrator->run(
                $USER,
                $context,
                $params['userprompt'],
                $params['conversationid'],
                null,
                $params['component'],
                [],
            );
        }

        return self::result_to_array($result);
    }

    /**
     * Describe return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'runid' => new external_value(PARAM_INT, 'DB id of the agent run'),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Run status: running|awaiting_approval|complete|failed'),
            'final_text' => new external_value(PARAM_RAW, 'Final assistant answer if the run is complete', VALUE_OPTIONAL),
            'iterations' => new external_value(PARAM_INT, 'Number of loop iterations performed'),
            'error_code' => new external_value(PARAM_ALPHANUMEXT, 'Stable error code if failed', VALUE_OPTIONAL),
            'error_message' => new external_value(PARAM_RAW, 'User-facing error message if failed', VALUE_OPTIONAL),
            'pending_approvals' => new external_multiple_structure(
                new external_single_structure([
                    'callindex' => new external_value(PARAM_INT, 'Call index in the run'),
                    'tool' => new external_value(PARAM_ALPHANUMEXT, 'Tool name'),
                    'describe' => new external_value(PARAM_RAW, 'User-facing description'),
                    'token' => new external_value(PARAM_RAW, 'HMAC approval token'),
                ]),
                'Tool calls that need explicit user approval',
                VALUE_DEFAULT,
                [],
            ),
        ]);
    }

    /**
     * Convert a run_result into the external API shape.
     *
     * @param run_result $result
     * @return array
     */
    private static function result_to_array(run_result $result): array {
        $pending = [];
        foreach ($result->pending_approvals as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $pending[] = [
                'callindex' => (int) ($entry['callindex'] ?? 0),
                'tool' => (string) ($entry['tool'] ?? ''),
                'describe' => (string) ($entry['describe'] ?? ''),
                'token' => (string) ($entry['token'] ?? ''),
            ];
        }
        return [
            'runid' => (int) $result->runid,
            'status' => (string) $result->status,
            'final_text' => (string) ($result->final_text ?? ''),
            'iterations' => (int) $result->iterations,
            'error_code' => (string) ($result->error_code ?? ''),
            'error_message' => (string) ($result->error_message ?? ''),
            'pending_approvals' => $pending,
        ];
    }
}

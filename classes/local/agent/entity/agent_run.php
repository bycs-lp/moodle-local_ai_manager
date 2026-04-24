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
 * Persistent entity for an agent run (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\local\agent\entity;

use core\persistent;

/**
 * Agent run entity.
 *
 * One row per chat turn that triggered the tool-agent purpose. Owns a cascade
 * of {@see tool_call} rows and is referenced from a block_ai_chat conversation.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class agent_run extends persistent {

    /** Table name. */
    public const TABLE = 'local_ai_manager_agent_runs';

    /** Status: orchestration in progress. */
    public const STATUS_RUNNING = 'running';
    /** Status: waiting for user to approve/reject a pending tool call. */
    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';
    /** Status: finished successfully with a final answer. */
    public const STATUS_COMPLETED = 'completed';
    /** Status: hard failure (parse/exception/tenant-scope-violation). */
    public const STATUS_FAILED = 'failed';
    /** Status: aborted because max-iterations were reached. */
    public const STATUS_ABORTED_MAXITER = 'aborted_maxiter';
    /** Status: aborted by the user via the UI (MBS-10761 Paket 2). */
    public const STATUS_ABORTED_USER = 'aborted_user';

    /** Protocol mode: native tool-calling. */
    public const MODE_NATIVE = 'native';
    /** Protocol mode: emulated via JSON-in-prompt (Telli etc.). */
    public const MODE_EMULATED = 'emulated';

    #[\Override]
    protected static function define_properties(): array {
        return [
            'conversationid' => [
                'type' => PARAM_INT,
                'default' => 0,
            ],
            'userid' => [
                'type' => PARAM_INT,
            ],
            'contextid' => [
                'type' => PARAM_INT,
            ],
            'tenantid' => [
                'type' => PARAM_INT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'component' => [
                'type' => PARAM_COMPONENT,
                'default' => 'block_ai_chat',
            ],
            'mode' => [
                'type' => PARAM_ALPHA,
                'choices' => [self::MODE_NATIVE, self::MODE_EMULATED],
            ],
            'connector' => [
                'type' => PARAM_ALPHANUMEXT,
            ],
            'model' => [
                'type' => PARAM_TEXT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'status' => [
                'type' => PARAM_ALPHANUMEXT,
                'default' => self::STATUS_RUNNING,
                'choices' => [
                    self::STATUS_RUNNING,
                    self::STATUS_AWAITING_APPROVAL,
                    self::STATUS_COMPLETED,
                    self::STATUS_FAILED,
                    self::STATUS_ABORTED_MAXITER,
                    self::STATUS_ABORTED_USER,
                ],
            ],
            'iterations' => [
                'type' => PARAM_INT,
                'default' => 0,
            ],
            'prompt_tokens' => [
                'type' => PARAM_INT,
                'default' => 0,
            ],
            'completion_tokens' => [
                'type' => PARAM_INT,
                'default' => 0,
            ],
            'overhead_tokens' => [
                'type' => PARAM_INT,
                'default' => 0,
            ],
            'entity_context' => [
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'user_prompt' => [
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'final_text' => [
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'error_code' => [
                'type' => PARAM_ALPHANUMEXT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'error_message' => [
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'started' => [
                'type' => PARAM_INT,
                'default' => 0,
            ],
            'finished' => [
                'type' => PARAM_INT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
        ];
    }
}

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
 * Persistent entity for a single tool call (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\local\agent\entity;

use core\persistent;

/**
 * Tool call entity.
 *
 * One row per tool invocation the orchestrator persists. Used for audit,
 * approval workflow, and undo history.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_call extends persistent {

    /** Table name. */
    public const TABLE = 'local_ai_manager_tool_calls';

    /** Approval state: read-only call, auto-approved. */
    public const APPROVAL_AUTO = 'auto';
    /** Approval state: waiting for the user. */
    public const APPROVAL_AWAITING = 'awaiting';
    /** Approval state: user approved and call executed. */
    public const APPROVAL_APPROVED = 'approved';
    /** Approval state: user rejected. */
    public const APPROVAL_REJECTED = 'rejected';
    /** Approval state: approved because session-trust was set. */
    public const APPROVAL_TRUSTED_SESSION = 'trusted_session';
    /** Approval state: approved because tenant-wide trust was configured. */
    public const APPROVAL_TRUSTED_GLOBAL = 'trusted_global';
    /** Approval state: approval token expired before user action. */
    public const APPROVAL_EXPIRED = 'expired';
    /** Approval state: user closed the tab, no answer within TTL. */
    public const APPROVAL_TIMEOUT = 'timeout';

    #[\Override]
    protected static function define_properties(): array {
        return [
            'runid' => [
                'type' => PARAM_INT,
            ],
            'callindex' => [
                'type' => PARAM_INT,
                'default' => 0,
            ],
            'llm_call_id' => [
                'type' => PARAM_ALPHANUMEXT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'toolname' => [
                'type' => PARAM_ALPHANUMEXT,
            ],
            'args_json' => [
                'type' => PARAM_RAW,
            ],
            'args_hash' => [
                'type' => PARAM_ALPHANUMEXT,
            ],
            'result_json' => [
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'approval_state' => [
                'type' => PARAM_ALPHANUMEXT,
                'default' => self::APPROVAL_AUTO,
                'choices' => [
                    self::APPROVAL_AUTO,
                    self::APPROVAL_AWAITING,
                    self::APPROVAL_APPROVED,
                    self::APPROVAL_REJECTED,
                    self::APPROVAL_TRUSTED_SESSION,
                    self::APPROVAL_TRUSTED_GLOBAL,
                    self::APPROVAL_EXPIRED,
                    self::APPROVAL_TIMEOUT,
                ],
            ],
            'approved_by' => [
                'type' => PARAM_INT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'approved_at' => [
                'type' => PARAM_INT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'duration_ms' => [
                'type' => PARAM_INT,
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
            'retry_count' => [
                'type' => PARAM_INT,
                'default' => 0,
            ],
            'undo_payload' => [
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'undone_at' => [
                'type' => PARAM_INT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'affected_objects' => [
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
        ];
    }
}

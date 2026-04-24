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
 * Tool-result value object (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent;

/**
 * Immutable result of a tool execution.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class tool_result {

    /**
     * Constructor.
     *
     * @param bool $ok whether the call succeeded
     * @param mixed $data JSON-serialisable payload that matches the tool's result schema
     * @param string|null $error stable error code (e.g. 'missing_capability', 'rejected_by_user')
     * @param string|null $user_message localised user-facing message
     * @param array $affected_objects [{type, id, label?}, ...] for the audit trail
     * @param array|null $undo_payload [tool, args] for an inverse call, null if not reversible
     * @param array $metrics free-form metrics (duration_ms, db_writes, ...)
     */
    public function __construct(
        public readonly bool $ok,
        public readonly mixed $data = null,
        public readonly ?string $error = null,
        public readonly ?string $user_message = null,
        public readonly array $affected_objects = [],
        public readonly ?array $undo_payload = null,
        public readonly array $metrics = [],
    ) {
    }

    /**
     * Build a success result.
     *
     * @param mixed $data
     * @param array $affected_objects
     * @param array|null $undo_payload
     * @param array $metrics
     * @return self
     */
    public static function success(
        mixed $data,
        array $affected_objects = [],
        ?array $undo_payload = null,
        array $metrics = [],
    ): self {
        return new self(
            ok: true,
            data: $data,
            affected_objects: $affected_objects,
            undo_payload: $undo_payload,
            metrics: $metrics,
        );
    }

    /**
     * Build a failure result.
     *
     * @param string $error stable error code
     * @param string|null $user_message
     * @param array $metrics
     * @return self
     */
    public static function failure(string $error, ?string $user_message = null, array $metrics = []): self {
        return new self(
            ok: false,
            error: $error,
            user_message: $user_message,
            metrics: $metrics,
        );
    }

    /**
     * Serialise to an array (for DB storage and LLM context).
     *
     * @return array
     */
    public function to_array(): array {
        return [
            'ok' => $this->ok,
            'data' => $this->data,
            'error' => $this->error,
            'user_message' => $this->user_message,
            'affected_objects' => $this->affected_objects,
            'undo_payload' => $this->undo_payload,
            'metrics' => $this->metrics,
        ];
    }
}

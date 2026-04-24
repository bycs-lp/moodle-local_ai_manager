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
 * Orchestrator return value (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent;

use local_ai_manager\local\agent\entity\agent_run;

/**
 * Immutable outcome of an {@see orchestrator::run()} / {@see orchestrator::resume()} call.
 *
 * The `status` matches the corresponding agent_run row; `pending_approvals` is populated
 * when the orchestrator paused because one or more tool calls require user confirmation.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class run_result {

    /**
     * Constructor.
     *
     * @param int $runid DB id of the {@see agent_run}
     * @param string $status one of {@see agent_run}::STATUS_* constants
     * @param string|null $final_text populated when status === COMPLETED
     * @param array $pending_approvals [{callid, callindex, tool, args, token, describe, affected}, ...]
     * @param int $iterations number of LLM round-trips consumed
     * @param array $tool_results chronological list of [{toolname, ok, data|error}, ...] for audit/UI
     * @param string|null $error_code stable error code when status === FAILED
     * @param string|null $error_message user-facing error message when status === FAILED
     */
    public function __construct(
        public readonly int $runid,
        public readonly string $status,
        public readonly ?string $final_text = null,
        public readonly array $pending_approvals = [],
        public readonly int $iterations = 0,
        public readonly array $tool_results = [],
        public readonly ?string $error_code = null,
        public readonly ?string $error_message = null,
    ) {
    }

    /**
     * True when the run reached a final answer.
     *
     * @return bool
     */
    public function is_complete(): bool {
        return $this->status === agent_run::STATUS_COMPLETED;
    }

    /**
     * True when the run paused and is waiting for user approvals.
     *
     * @return bool
     */
    public function is_awaiting_approval(): bool {
        return $this->status === agent_run::STATUS_AWAITING_APPROVAL;
    }
}

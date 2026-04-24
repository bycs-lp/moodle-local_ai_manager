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
 * Undo manager (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent;

use local_ai_manager\local\agent\entity\tool_call;

/**
 * Reverse reversible tool calls within the configured window.
 *
 * Each reversible tool returns an undo_payload ({tool, args}) after successful execution;
 * the orchestrator stores it in the tool_call row. Within the site-configured
 * `agent_undo_window_seconds` window the user can invoke `undo()` to run the inverse
 * call. The inverse tool is resolved through the registry — it does NOT need to be in
 * the LLM-visible catalog (typical example: `course_delete` as the undo target of
 * `course_create`).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class undo_manager {

    /**
     * Constructor.
     *
     * @param tool_registry $registry
     * @param \core\clock $clock
     */
    public function __construct(
        private readonly tool_registry $registry,
        private readonly \core\clock $clock,
    ) {
    }

    /**
     * Check if an undo is still allowed for the given tool call.
     *
     * @param tool_call $call
     * @return bool
     */
    public function can_undo(tool_call $call): bool {
        $window = (int) get_config('local_ai_manager', 'agent_undo_window_seconds');
        if ($window <= 0) {
            return false;
        }
        if ($call->get('undone_at')) {
            return false;
        }
        $payload = $call->get('undo_payload');
        if (empty($payload)) {
            return false;
        }
        $now = $this->clock->now()->getTimestamp();
        return ($now - (int) $call->get('timecreated')) <= $window;
    }

    /**
     * Execute the inverse operation for a tool call.
     *
     * @param tool_call $call
     * @param execution_context $ctx
     * @return tool_result
     * @throws \moodle_exception when the undo window has expired
     */
    public function undo(tool_call $call, execution_context $ctx): tool_result {
        if (!$this->can_undo($call)) {
            throw new \moodle_exception('agent_undo_window_expired', 'local_ai_manager');
        }
        $payload = json_decode((string) $call->get('undo_payload'), true);
        if (!is_array($payload) || empty($payload['tool'])) {
            throw new \moodle_exception('agent_undo_payload_invalid', 'local_ai_manager');
        }
        $tool = $this->registry->get_by_name((string) $payload['tool']);
        $result = $tool->execute((array) ($payload['args'] ?? []), $ctx);
        $call->set('undone_at', $this->clock->now()->getTimestamp());
        $call->save();
        return $result;
    }
}

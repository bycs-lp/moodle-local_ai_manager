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
 * Tool-Agent purpose class.
 *
 * Implements the aipurpose_toolagent subplugin which orchestrates tool-calling agent
 * interactions on top of local_ai_manager. The heavy lifting (tool registry, orchestrator,
 * approval flow) lives in \local_ai_manager\agent\*. This purpose acts as the thin entry
 * point that hooks into the manager request lifecycle.
 *
 * @package    aipurpose_toolagent
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aipurpose_toolagent;

use local_ai_manager\base_purpose;

/**
 * Tool-Agent purpose.
 *
 * Delegates to \local_ai_manager\agent\orchestrator. The orchestrator itself is
 * implemented in Baustein 5; this class only ships the stable entry points and
 * option surface so that connectors and UI can be wired up in parallel.
 *
 * @package    aipurpose_toolagent
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purpose extends base_purpose {

    /** @var array Options captured from the frontend, used during format_output. */
    private array $storedoptions = [];

    #[\Override]
    public function get_additional_purpose_options(): array {
        return [
            // Client-side flag indicating the tool-agent mode is active.
            'tools_enabled'     => PARAM_BOOL,
            // Server-generated run ID used to resume the loop after approval.
            'agent_run_id'      => PARAM_INT,
            // Explicit tool allowlist (comma-separated names) for pilot tenants.
            'tools_allowlist'   => PARAM_TEXT,
            // Draft item IDs of uploaded files available to tools.
            'draftitemids'      => PARAM_TEXT,
            // Serialised page context (pageid, courseid, cmid) for the entity tracker.
            'page_context'      => PARAM_RAW,
            // Protocol mode: 'native' or 'emulated'. Set by the orchestrator based on connector.
            'protocol_mode'     => PARAM_ALPHA,
        ];
    }

    #[\Override]
    public function get_additional_request_options(array $options): array {
        // Store the options so format_output() can retrieve the run ID and mode.
        $this->storedoptions = $options;

        // The full orchestrator wiring (tool schemas, history, system prompt assembly)
        // lives in Baustein 5. This stub keeps the pipeline green until then by
        // forwarding the options unchanged. The orchestrator will attach
        // 'agent_tools', 'agent_history', 'system_prompt_extras' here.
        return $options;
    }

    #[\Override]
    public function format_output(string $output): string {
        // Baustein 5 will plug in orchestrator::process_llm_output() here and
        // parse tool calls from $output. Until then we return the raw text so
        // existing chat flows are not broken.
        return $output;
    }
}

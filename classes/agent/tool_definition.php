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
 * Tool definition contract for the MBS-10761 tool-agent.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent;

/**
 * Contract every agent tool (core or 3rd party) must satisfy.
 *
 * Tools wrap public Moodle APIs behind a uniform, LLM-consumable interface.
 * Implementations live under `\local_ai_manager\agent\tools\<category>\*` for
 * core tools; 3rd-party plugins declare their tools in `db/agenttools.php`.
 *
 * Each concrete implementation MUST follow the documentation contract defined
 * in KONZEPT_AI_CHAT_AGENT.md §3.2.1 (Use-this-tool-when / Do-not / Examples)
 * — the description is the central quality factor for tool discovery.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface tool_definition {

    /**
     * Machine name, snake_case, globally unique. Frankenstyle prefix for 3rd-party tools.
     *
     * @return string
     */
    public function get_name(): string;

    /**
     * Short one-sentence summary (localised via get_string).
     *
     * @return string
     */
    public function get_summary(): string;

    /**
     * LLM-directed description as per the documentation contract (English, hardcoded).
     *
     * MUST include `Use this tool when`, `Do NOT use this tool when`, `Behavior`
     * and `Examples` sections. Minimum 200 characters. The tool metadata linter
     * rejects deviations.
     *
     * @return string
     */
    public function get_description(): string;

    /**
     * Category for two-step discovery (category_gated strategy).
     *
     * Typical values: course, forum, quiz, question, file, image, reports, user.
     *
     * @return string
     */
    public function get_category(): string;

    /**
     * JSON-Schema (Draft 2020-12) of the parameter object. Each property MUST have a description.
     *
     * @return array
     */
    public function get_parameters_schema(): array;

    /**
     * JSON-Schema of the return value.
     *
     * @return array
     */
    public function get_result_schema(): array;

    /**
     * True if the call MUST receive an explicit user approval before execution.
     *
     * @return bool
     */
    public function requires_approval(): bool;

    /**
     * Moodle capabilities required by the user for this tool (pre-filter).
     *
     * @return string[]
     */
    public function get_required_capabilities(): array;

    /**
     * Keywords for retrieval strategy and auto-promote nudge.
     *
     * @return string[]
     */
    public function get_keywords(): array;

    /**
     * Runtime availability check for the given moodle context and user.
     *
     * @param \core\context $ctx
     * @param int $userid
     * @return bool
     */
    public function is_available_for(\core\context $ctx, int $userid): bool;

    /**
     * True if the same arguments may be replayed safely (no additional side effects).
     *
     * @return bool
     */
    public function is_idempotent(): bool;

    /**
     * True if an inverse operation exists; build_undo_payload() MUST return non-null.
     *
     * @return bool
     */
    public function is_reversible(): bool;

    /**
     * True if the orchestrator may execute this tool in parallel with others.
     *
     * Default for write-tools is false.
     *
     * @return bool
     */
    public function supports_parallel(): bool;

    /**
     * Per-call timeout in seconds. Default 30.
     *
     * @return int
     */
    public function get_timeout_seconds(): int;

    /**
     * Natural-language summary for the approval card (localised).
     *
     * @param array $args tool arguments
     * @return string
     */
    public function describe_for_user(array $args): string;

    /**
     * Returns the moodle objects a successful execution would touch (for audit log + dry-run).
     *
     * @param array $args
     * @return array list of {type, id, label?} entries.
     */
    public function get_affected_objects(array $args): array;

    /**
     * Optional dry-run description. Return null to let the orchestrator derive a default from
     * describe_for_user() + get_affected_objects().
     *
     * @param array $args
     * @return string|null
     */
    public function dry_run(array $args): ?string;

    /**
     * Execute the tool call. Any exception is caught by the orchestrator and wrapped as tool_result.
     *
     * @param array $args
     * @param execution_context $ctx
     * @return tool_result
     */
    public function execute(array $args, execution_context $ctx): tool_result;

    /**
     * Build the undo payload ({tool, args}) describing the inverse operation.
     *
     * Only invoked when is_reversible() === true.
     *
     * @param array $args
     * @param tool_result $result
     * @return array|null
     */
    public function build_undo_payload(array $args, tool_result $result): ?array;
}

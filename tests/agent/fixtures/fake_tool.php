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
 * Configurable tool for orchestrator tests (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent\tests\fixtures;

use local_ai_manager\agent\execution_context;
use local_ai_manager\agent\tool_definition;
use local_ai_manager\agent\tool_result;

/**
 * Test-only tool_definition whose behaviour is controlled via constructor options.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class fake_tool implements tool_definition {

    /** @var array list of {args, ctx_runid} for each execute() call. */
    public array $invocations = [];

    /**
     * Constructor.
     *
     * @param string $name
     * @param bool $requiresapproval
     * @param callable|null $executor function(array $args, execution_context $ctx): tool_result
     * @param array $affectedobjects list returned by get_affected_objects()
     * @param string $category
     * @param string[] $requiredcaps
     */
    public function __construct(
        private readonly string $name = 'fake_tool',
        private readonly bool $requiresapproval = false,
        private readonly mixed $executor = null,
        private readonly array $affectedobjects = [],
        private readonly string $category = 'test',
        private readonly array $requiredcaps = [],
    ) {
    }

    #[\Override]
    public function get_name(): string {
        return $this->name;
    }

    #[\Override]
    public function get_summary(): string {
        return 'Fake tool for unit tests';
    }

    #[\Override]
    public function get_description(): string {
        return 'Use this tool when writing unit tests for the orchestrator. '
            . 'Do NOT use this tool in production — it has no real side effects. '
            . 'Behavior: returns whatever the test executor configured. '
            . 'Examples: tests/agent/orchestrator_test.php.';
    }

    #[\Override]
    public function get_category(): string {
        return $this->category;
    }

    #[\Override]
    public function get_parameters_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'value' => ['type' => 'string', 'description' => 'Arbitrary value.'],
            ],
        ];
    }

    #[\Override]
    public function get_result_schema(): array {
        return ['type' => 'object'];
    }

    #[\Override]
    public function requires_approval(): bool {
        return $this->requiresapproval;
    }

    #[\Override]
    public function get_required_capabilities(): array {
        return $this->requiredcaps;
    }

    #[\Override]
    public function get_keywords(): array {
        return ['test', 'fake'];
    }

    #[\Override]
    public function is_available_for(\core\context $ctx, int $userid): bool {
        return true;
    }

    #[\Override]
    public function is_idempotent(): bool {
        return true;
    }

    #[\Override]
    public function is_reversible(): bool {
        return false;
    }

    #[\Override]
    public function supports_parallel(): bool {
        return true;
    }

    #[\Override]
    public function get_timeout_seconds(): int {
        return 5;
    }

    #[\Override]
    public function describe_for_user(array $args): string {
        return 'Fake call with args ' . json_encode($args);
    }

    #[\Override]
    public function get_affected_objects(array $args): array {
        return $this->affectedobjects;
    }

    #[\Override]
    public function dry_run(array $args): ?string {
        return null;
    }

    #[\Override]
    public function execute(array $args, execution_context $ctx): tool_result {
        $this->invocations[] = ['args' => $args, 'runid' => $ctx->runid, 'callid' => $ctx->callid];
        if (is_callable($this->executor)) {
            return ($this->executor)($args, $ctx);
        }
        return tool_result::success(['echo' => $args]);
    }

    #[\Override]
    public function build_undo_payload(array $args, tool_result $result): ?array {
        return null;
    }
}

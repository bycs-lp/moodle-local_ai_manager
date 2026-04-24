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
 * Tests for the tool-description fallback resolver (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent;

/**
 * Tests for {@see tool_description_resolver}.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_manager\agent\tool_description_resolver
 */
final class tool_description_resolver_test extends \advanced_testcase {

    /**
     * Build a minimal anonymous tool definition for tests.
     *
     * @param string $name
     * @param string $description
     * @return tool_definition
     */
    private function build_tool(string $name, string $description): tool_definition {
        return new class ($name, $description) implements tool_definition {
            public function __construct(
                private readonly string $name,
                private readonly string $description,
            ) {
            }
            public function get_name(): string {
                return $this->name;
            }
            public function get_summary(): string {
                return 'summary';
            }
            public function get_description(): string {
                return $this->description;
            }
            public function get_category(): string {
                return 'test';
            }
            public function get_parameters_schema(): array {
                return ['type' => 'object', 'properties' => []];
            }
            public function get_result_schema(): array {
                return ['type' => 'object', 'properties' => []];
            }
            public function requires_approval(): bool {
                return false;
            }
            public function get_required_capabilities(): array {
                return [];
            }
            public function get_keywords(): array {
                return [];
            }
            public function is_available_for(\core\context $ctx, int $userid): bool {
                return true;
            }
            public function is_idempotent(): bool {
                return true;
            }
            public function is_reversible(): bool {
                return false;
            }
            public function supports_parallel(): bool {
                return true;
            }
            public function get_timeout_seconds(): int {
                return 30;
            }
            public function describe_for_user(array $args): string {
                return 'description';
            }
            public function get_affected_objects(array $args): array {
                return [];
            }
            public function dry_run(array $args): ?string {
                return null;
            }
            public function execute(array $args, execution_context $ctx): tool_result {
                return tool_result::success([]);
            }
            public function build_undo_payload(array $args, tool_result $result): ?array {
                return null;
            }
        };
    }

    /**
     * Absent an override row, the hardcoded description is returned verbatim.
     */
    public function test_returns_hardcoded_default_without_override(): void {
        $this->resetAfterTest();
        $tool = $this->build_tool('x_tool', 'Hardcoded default description.');
        $resolver = new tool_description_resolver();
        $this->assertSame('Hardcoded default description.', $resolver->resolve($tool));
    }

    /**
     * A site-level override replaces the hardcoded default.
     */
    public function test_site_override_replaces_default(): void {
        global $DB;
        $this->resetAfterTest();

        $DB->insert_record('local_ai_manager_tool_overrides', (object) [
            'toolname' => 'x_tool',
            'tenantid' => null,
            'llm_description_override' => 'Site override wins.',
            'enabled' => 1,
            'timecreated' => 1700000000,
            'timemodified' => 1700000000,
        ]);

        $tool = $this->build_tool('x_tool', 'Hardcoded default.');
        $resolver = new tool_description_resolver();
        $this->assertStringContainsString('Site override wins.', $resolver->resolve($tool));
    }

    /**
     * A tenant-level override wins over a site-level override.
     */
    public function test_tenant_override_wins_over_site_override(): void {
        global $DB;
        $this->resetAfterTest();

        $DB->insert_record('local_ai_manager_tool_overrides', (object) [
            'toolname' => 'x_tool',
            'tenantid' => null,
            'llm_description_override' => 'Site.',
            'enabled' => 1,
            'timecreated' => 1700000000,
            'timemodified' => 1700000000,
        ]);
        $DB->insert_record('local_ai_manager_tool_overrides', (object) [
            'toolname' => 'x_tool',
            'tenantid' => 42,
            'llm_description_override' => 'Tenant.',
            'enabled' => 1,
            'timecreated' => 1700000000,
            'timemodified' => 1700000000,
        ]);

        $tool = $this->build_tool('x_tool', 'Hard.');
        $resolver = new tool_description_resolver();
        $this->assertStringContainsString('Tenant.', $resolver->resolve($tool, 42));
        $this->assertStringNotContainsString('Site.', $resolver->resolve($tool, 42));
    }

    /**
     * A disabled override is ignored — fallback to default.
     */
    public function test_disabled_override_is_ignored(): void {
        global $DB;
        $this->resetAfterTest();
        $DB->insert_record('local_ai_manager_tool_overrides', (object) [
            'toolname' => 'x_tool',
            'tenantid' => null,
            'llm_description_override' => 'Disabled override.',
            'enabled' => 0,
            'timecreated' => 1700000000,
            'timemodified' => 1700000000,
        ]);

        $tool = $this->build_tool('x_tool', 'Hardcoded.');
        $resolver = new tool_description_resolver();
        $this->assertSame('Hardcoded.', $resolver->resolve($tool));
    }
}

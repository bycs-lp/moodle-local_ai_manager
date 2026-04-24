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
 * Tests for tool_rate_limiter (MBS-10761 Paket 3).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent;

use local_ai_manager\agent\exception\rate_limit_exceeded_exception;

/**
 * @covers \local_ai_manager\agent\tool_rate_limiter
 */
final class tool_rate_limiter_test extends \advanced_testcase {

    /**
     * Build a fake tool_definition with a controllable name + approval flag.
     *
     * @param string $name
     * @param bool $requiresapproval
     * @return tool_definition
     */
    private function make_tool(string $name, bool $requiresapproval): tool_definition {
        return new class($name, $requiresapproval) implements tool_definition {
            /**
             * Constructor.
             *
             * @param string $name
             * @param bool $requiresapproval
             */
            public function __construct(private string $name, private bool $requiresapproval) {}
            public function get_name(): string { return $this->name; }
            public function get_summary(): string { return ''; }
            public function get_description(): string { return ''; }
            public function get_category(): string { return 'test'; }
            public function get_keywords(): array { return []; }
            public function get_parameters_schema(): array { return ['type' => 'object']; }
            public function get_result_schema(): array { return []; }
            public function requires_approval(): bool { return $this->requiresapproval; }
            public function is_idempotent(): bool { return true; }
            public function is_reversible(): bool { return false; }
            public function supports_parallel(): bool { return true; }
            public function get_timeout_seconds(): int { return 30; }
            public function get_required_capabilities(): array { return []; }
            public function is_available_for(\core\context $ctx, int $userid): bool { return true; }
            public function get_affected_objects(array $args): array { return []; }
            public function describe_for_user(array $args): string { return ''; }
            public function dry_run(array $args): ?string { return null; }
            public function build_undo_payload(array $args, tool_result $result): ?array { return null; }
            public function execute(array $args, execution_context $ctx): tool_result {
                return tool_result::success([]);
            }
        };
    }

    /**
     * Default limit kicks in for read tools (200/h).
     */
    public function test_default_read_limit(): void {
        $this->resetAfterTest();
        $limiter = new tool_rate_limiter(\core\di::get(\core\clock::class));
        $this->assertSame(
            tool_rate_limiter::DEFAULT_READ_PER_HOUR,
            $limiter->get_limit_for($this->make_tool('course_find', false))
        );
    }

    /**
     * Default limit kicks in for write tools (50/h).
     */
    public function test_default_write_limit(): void {
        $this->resetAfterTest();
        $limiter = new tool_rate_limiter(\core\di::get(\core\clock::class));
        $this->assertSame(
            tool_rate_limiter::DEFAULT_WRITE_PER_HOUR,
            $limiter->get_limit_for($this->make_tool('course_create', true))
        );
    }

    /**
     * Per-tool admin setting overrides defaults.
     */
    public function test_per_tool_override(): void {
        $this->resetAfterTest();
        set_config('agent_ratelimit_course_create', 5, 'local_ai_manager');
        $limiter = new tool_rate_limiter(\core\di::get(\core\clock::class));
        $this->assertSame(5, $limiter->get_limit_for($this->make_tool('course_create', true)));
    }

    /**
     * Counter increments until the limit is hit, then throws.
     */
    public function test_check_and_increment_enforces_limit(): void {
        $this->resetAfterTest();
        set_config('agent_ratelimit_course_create', 3, 'local_ai_manager');
        $limiter = new tool_rate_limiter(\core\di::get(\core\clock::class));
        $tool = $this->make_tool('course_create', true);

        $limiter->check_and_increment(42, $tool);
        $limiter->check_and_increment(42, $tool);
        $limiter->check_and_increment(42, $tool);

        $this->assertSame(3, $limiter->current_count(42, 'course_create'));
        $this->expectException(rate_limit_exceeded_exception::class);
        $limiter->check_and_increment(42, $tool);
    }

    /**
     * A limit of 0 disables enforcement entirely.
     */
    public function test_zero_limit_disables_enforcement(): void {
        $this->resetAfterTest();
        set_config('agent_ratelimit_course_find', 0, 'local_ai_manager');
        $limiter = new tool_rate_limiter(\core\di::get(\core\clock::class));
        $tool = $this->make_tool('course_find', false);
        for ($i = 0; $i < 10; $i++) {
            $limiter->check_and_increment(42, $tool);
        }
        // Count stays at zero because the limiter short-circuits before writing.
        $this->assertSame(0, $limiter->current_count(42, 'course_find'));
    }

    /**
     * Counter is scoped per user.
     */
    public function test_counter_is_per_user(): void {
        $this->resetAfterTest();
        set_config('agent_ratelimit_course_create', 2, 'local_ai_manager');
        $limiter = new tool_rate_limiter(\core\di::get(\core\clock::class));
        $tool = $this->make_tool('course_create', true);

        $limiter->check_and_increment(1, $tool);
        $limiter->check_and_increment(1, $tool);
        // User 2 still has budget.
        $limiter->check_and_increment(2, $tool);

        $this->assertSame(2, $limiter->current_count(1, 'course_create'));
        $this->assertSame(1, $limiter->current_count(2, 'course_create'));
    }
}

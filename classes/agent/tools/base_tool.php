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
 * Base class for core agent tools (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent\tools;

use local_ai_manager\agent\execution_context;
use local_ai_manager\agent\tool_definition;
use local_ai_manager\agent\tool_result;

/**
 * Sensible defaults for a core tool implementation.
 *
 * Subclasses only need to override the handful of methods that carry real meaning:
 * get_name(), get_summary(), get_description(), get_category(),
 * get_parameters_schema(), get_result_schema() and run().
 *
 * The remaining tool_definition methods fall back to read-only / idempotent defaults,
 * which matches the majority of core tools.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base_tool implements tool_definition {

    #[\Override]
    public function requires_approval(): bool {
        return false;
    }

    #[\Override]
    public function get_required_capabilities(): array {
        return [];
    }

    #[\Override]
    public function get_keywords(): array {
        return [];
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
        return !$this->requires_approval();
    }

    #[\Override]
    public function get_timeout_seconds(): int {
        return 30;
    }

    #[\Override]
    public function get_affected_objects(array $args): array {
        return [];
    }

    #[\Override]
    public function dry_run(array $args): ?string {
        return null;
    }

    #[\Override]
    public function describe_for_user(array $args): string {
        return $this->get_summary();
    }

    #[\Override]
    public function build_undo_payload(array $args, tool_result $result): ?array {
        return null;
    }

    #[\Override]
    public function execute(array $args, execution_context $ctx): tool_result {
        $start = microtime(true);
        try {
            $result = $this->run($args, $ctx);
            // Merge a duration metric when the subclass did not supply one.
            if (!isset($result->metrics['duration_ms'])) {
                $metrics = $result->metrics + ['duration_ms' => (int) round((microtime(true) - $start) * 1000)];
                return new tool_result(
                    ok: $result->ok,
                    data: $result->data,
                    error: $result->error,
                    user_message: $result->user_message,
                    affected_objects: $result->affected_objects,
                    undo_payload: $result->undo_payload,
                    metrics: $metrics,
                );
            }
            return $result;
        } catch (\moodle_exception $e) {
            return tool_result::failure(
                $e->errorcode ?: 'moodle_exception',
                $e->getMessage(),
                ['duration_ms' => (int) round((microtime(true) - $start) * 1000)],
            );
        } catch (\Throwable $t) {
            return tool_result::failure(
                'execution_exception',
                $t->getMessage(),
                ['duration_ms' => (int) round((microtime(true) - $start) * 1000)],
            );
        }
    }

    /**
     * Concrete tool logic. Exceptions are caught by execute().
     *
     * @param array $args validated tool arguments
     * @param execution_context $ctx
     * @return tool_result
     */
    abstract protected function run(array $args, execution_context $ctx): tool_result;
}

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
 * WS-backed tool adapter (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent;

use core_external\external_api;

/**
 * Tool adapter that delegates to an existing Moodle external-service function.
 *
 * Metadata carried in the registration array (see classes/agent/ws_tools.php):
 *   - wsfunction       string  Moodle WS function name
 *   - toolname         string  agent-side tool name (snake_case)
 *   - category         string  agent category
 *   - llm_description  string  hardcoded LLM-facing description (SPEZ §18)
 *   - summary          string  1-sentence summary
 *   - keywords         array   optional keywords
 *   - requires_approval bool   default false (read-only tools)
 *   - is_reversible    bool    default false
 *   - timeout_seconds  int     default 30
 *   - capabilities     array   required moodle capabilities (falls back to ws definition)
 *
 * The adapter auto-derives JSON-Schemas from the WS parameter and return definitions
 * via {@see ws_schema_converter}.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ws_backed_tool_definition implements tool_definition {

    /** @var array Registration metadata. */
    private array $meta;

    /** @var \stdClass|null lazily resolved external_function_info. */
    private ?\stdClass $wsinfo = null;

    /**
     * Constructor.
     *
     * @param array $meta registration metadata (see class docblock).
     */
    public function __construct(array $meta) {
        foreach (['wsfunction', 'toolname', 'category', 'llm_description', 'summary'] as $required) {
            if (empty($meta[$required])) {
                throw new \coding_exception("ws_backed_tool_definition: '{$required}' is required.");
            }
        }
        $this->meta = $meta + [
            'keywords' => [],
            'requires_approval' => false,
            'is_reversible' => false,
            'is_idempotent' => true,
            'supports_parallel' => false,
            'timeout_seconds' => 30,
            'capabilities' => [],
        ];
    }

    #[\Override]
    public function get_name(): string {
        return $this->meta['toolname'];
    }

    #[\Override]
    public function get_summary(): string {
        return $this->meta['summary'];
    }

    #[\Override]
    public function get_description(): string {
        return $this->meta['llm_description'];
    }

    #[\Override]
    public function get_category(): string {
        return $this->meta['category'];
    }

    #[\Override]
    public function get_parameters_schema(): array {
        $info = $this->ws_info();
        return ws_schema_converter::convert($info->parameters_desc);
    }

    #[\Override]
    public function get_result_schema(): array {
        $info = $this->ws_info();
        if ($info->returns_desc === null) {
            return ['type' => 'null'];
        }
        return ws_schema_converter::convert($info->returns_desc);
    }

    #[\Override]
    public function requires_approval(): bool {
        return (bool) $this->meta['requires_approval'];
    }

    #[\Override]
    public function get_required_capabilities(): array {
        if (!empty($this->meta['capabilities'])) {
            return $this->meta['capabilities'];
        }
        $info = $this->ws_info();
        return !empty($info->capabilities) ? explode(',', $info->capabilities) : [];
    }

    #[\Override]
    public function get_keywords(): array {
        return (array) $this->meta['keywords'];
    }

    #[\Override]
    public function is_available_for(\core\context $ctx, int $userid): bool {
        return true;
    }

    #[\Override]
    public function is_idempotent(): bool {
        return (bool) $this->meta['is_idempotent'];
    }

    #[\Override]
    public function is_reversible(): bool {
        return (bool) $this->meta['is_reversible'];
    }

    #[\Override]
    public function supports_parallel(): bool {
        return (bool) $this->meta['supports_parallel'];
    }

    #[\Override]
    public function get_timeout_seconds(): int {
        return (int) $this->meta['timeout_seconds'];
    }

    #[\Override]
    public function describe_for_user(array $args): string {
        // The concrete override UI (Baustein 8) will supply a nicer template;
        // until then we fall back to a compact listing of arguments.
        $keyvals = [];
        foreach ($args as $k => $v) {
            $keyvals[] = $k . '=' . (is_scalar($v) ? (string) $v : '…');
        }
        return $this->meta['summary'] . ' (' . implode(', ', $keyvals) . ')';
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
    public function execute(array $args, execution_context $ctx): tool_result {
        $start = microtime(true);
        try {
            $response = external_api::call_external_function(
                $this->meta['wsfunction'],
                $args,
                false,
            );
            $duration = (int) round((microtime(true) - $start) * 1000);
            if (!empty($response['error'])) {
                return new tool_result(
                    ok: false,
                    error: 'ws_error',
                    user_message: (string) ($response['exception']->message ?? 'Web service error.'),
                    metrics: ['duration_ms' => $duration],
                );
            }
            return tool_result::success(
                data: $response['data'] ?? null,
                metrics: ['duration_ms' => $duration],
            );
        } catch (\Throwable $t) {
            return new tool_result(
                ok: false,
                error: 'execution_exception',
                user_message: $t->getMessage(),
                metrics: ['duration_ms' => (int) round((microtime(true) - $start) * 1000)],
            );
        }
    }

    #[\Override]
    public function build_undo_payload(array $args, tool_result $result): ?array {
        return null;
    }

    /**
     * Resolve and cache the external_function_info for the wrapped WS function.
     *
     * @return \stdClass
     */
    private function ws_info(): \stdClass {
        if ($this->wsinfo === null) {
            $this->wsinfo = external_api::external_function_info($this->meta['wsfunction']);
        }
        return $this->wsinfo;
    }
}

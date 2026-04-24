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
 * Tool: module_delete (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent\tools\mod;

use local_ai_manager\agent\execution_context;
use local_ai_manager\agent\tool_result;
use local_ai_manager\agent\tools\base_tool;

/**
 * Delete an activity (course module).
 *
 * High-risk write tool: requires explicit approval and is NOT marked reversible
 * because Moodle's course_delete_module() drops user data synchronously.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class module_delete extends base_tool {

    #[\Override]
    public function get_name(): string {
        return 'module_delete';
    }

    #[\Override]
    public function get_summary(): string {
        return get_string('tool_module_delete_summary', 'local_ai_manager');
    }

    #[\Override]
    public function get_description(): string {
        return <<<EOT
Use this tool ONLY when the user explicitly asks to delete a specific activity or
resource by cmid. Typical triggers: "Lösche Aktivität 123", "Remove this forum".
Confirm the exact cmid with the user first if there is any ambiguity.

Do NOT use this tool to hide an activity (use module_update with visible=false)
or to reset its data. Do NOT use it to delete a course or a section.

Behavior: Requires {cmid, confirm:true}. The `confirm` flag must be literally
true to guard against accidental calls. Deletion is permanent and all related
user data (submissions, attempts, grades) is removed. The tool requires
explicit approval and is NOT reversible.

Examples:
  - "Lösche Aktivität 123" -> module_delete({cmid:123, confirm:true})
EOT;
    }

    #[\Override]
    public function get_category(): string {
        return 'mod';
    }

    #[\Override]
    public function get_keywords(): array {
        return ['module', 'activity', 'delete', 'remove', 'löschen', 'entfernen'];
    }

    #[\Override]
    public function requires_approval(): bool {
        return true;
    }

    #[\Override]
    public function is_idempotent(): bool {
        return false;
    }

    #[\Override]
    public function is_reversible(): bool {
        return false;
    }

    #[\Override]
    public function supports_parallel(): bool {
        return false;
    }

    #[\Override]
    public function get_timeout_seconds(): int {
        return 120;
    }

    #[\Override]
    public function get_parameters_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'cmid' => [
                    'type' => 'integer',
                    'description' => 'Course module id (cmid) to delete.',
                    'minimum' => 1,
                ],
                'confirm' => [
                    'type' => 'boolean',
                    'description' => 'Must be true. Safety flag — set deliberately.',
                    'enum' => [true],
                ],
            ],
            'required' => ['cmid', 'confirm'],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function get_result_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'cmid' => ['type' => 'integer'],
                'deleted' => ['type' => 'boolean'],
            ],
        ];
    }

    #[\Override]
    public function get_affected_objects(array $args): array {
        return [[
            'type' => 'course_module',
            'id' => (int) ($args['cmid'] ?? 0),
            'label' => 'cm ' . (int) ($args['cmid'] ?? 0),
        ]];
    }

    #[\Override]
    public function describe_for_user(array $args): string {
        return get_string('tool_module_delete_describe', 'local_ai_manager', (object) [
            'cmid' => (int) ($args['cmid'] ?? 0),
        ]);
    }

    #[\Override]
    protected function run(array $args, execution_context $ctx): tool_result {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $cmid = (int) $args['cmid'];
        if (empty($args['confirm'])) {
            return tool_result::failure('confirm_required',
                get_string('tool_module_delete_confirm_required', 'local_ai_manager'));
        }
        $cm = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return tool_result::failure('cm_not_found',
                get_string('tool_module_not_found', 'local_ai_manager'));
        }
        $modctx = \core\context\module::instance($cm->id);
        require_capability('moodle/course:manageactivities', $modctx, $ctx->user);

        course_delete_module($cmid);

        return tool_result::success(
            data: [
                'cmid' => $cmid,
                'deleted' => true,
            ],
            affected_objects: [[
                'type' => 'course_module',
                'id' => $cmid,
                'label' => $cm->modname,
            ]],
        );
    }
}

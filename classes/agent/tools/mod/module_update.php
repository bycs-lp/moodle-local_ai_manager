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
 * Tool: module_update (MBS-10761).
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
 * Update name / intro / visibility of an existing activity.
 *
 * Intentionally limited to the three most-requested fields. Scope-heavy updates
 * (quiz settings, assignment rubric, …) remain out of the agent's reach.
 *
 * Write-tool, requires explicit approval, reversible by restoring the previous
 * values from the undo payload.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class module_update extends base_tool {

    #[\Override]
    public function get_name(): string {
        return 'module_update';
    }

    #[\Override]
    public function get_summary(): string {
        return get_string('tool_module_update_summary', 'local_ai_manager');
    }

    #[\Override]
    public function get_description(): string {
        return <<<EOT
Use this tool to rename an activity, change its intro/description, toggle its
visibility, or replace the body text of a mod_page. Typical triggers: "Benenne
das Forum um in …", "Mach die Aktivität unsichtbar", "Ändere die Beschreibung
der Aufgabe", "Setze den Text der Seite auf …".

Do NOT use this tool to edit quiz settings, grading or attempts; those fields are
intentionally not exposed here. Do NOT use it to move an activity between
sections — that is not supported yet.

Behavior: Requires `cmid` plus at least one of {name, intro, visible, content}.
Omitted fields stay unchanged. `content` is only valid for mod_page activities
and replaces the page body (HTML). Returns the previous values so the call is
reversible. Requires explicit approval.

Examples:
  - "Benenne Aktivität 123 in 'Projektforum' um" -> module_update({cmid:123, name:"Projektforum"})
  - "Blende Aktivität 123 aus" -> module_update({cmid:123, visible:false})
  - "Setze den Text der Seite 456 auf '<p>Hallo</p>'" -> module_update({cmid:456, content:"<p>Hallo</p>"})
EOT;
    }

    #[\Override]
    public function get_category(): string {
        return 'mod';
    }

    #[\Override]
    public function get_keywords(): array {
        return ['module', 'activity', 'update', 'rename', 'umbenennen', 'beschreibung'];
    }

    #[\Override]
    public function requires_approval(): bool {
        return true;
    }

    #[\Override]
    public function is_reversible(): bool {
        return true;
    }

    #[\Override]
    public function supports_parallel(): bool {
        return false;
    }

    #[\Override]
    public function get_parameters_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'cmid' => [
                    'type' => 'integer',
                    'description' => 'Course module id (cmid).',
                    'minimum' => 1,
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'New display name (1-250 chars). Omit to keep current.',
                    'minLength' => 1,
                    'maxLength' => 250,
                ],
                'intro' => [
                    'type' => 'string',
                    'description' => 'New HTML intro/description. Omit to keep current.',
                    'maxLength' => 65535,
                ],
                'visible' => [
                    'type' => 'boolean',
                    'description' => 'Visibility flag. Omit to keep current.',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'New HTML body text. Only supported for mod_page. Omit to keep current.',
                    'maxLength' => 1048576,
                ],
            ],
            'required' => ['cmid'],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function get_result_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'cmid' => ['type' => 'integer'],
                'previous' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'intro' => ['type' => 'string'],
                        'visible' => ['type' => 'boolean'],
                        'content' => ['type' => 'string'],
                    ],
                ],
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
        return get_string('tool_module_update_describe', 'local_ai_manager', (object) [
            'cmid' => (int) ($args['cmid'] ?? 0),
        ]);
    }

    #[\Override]
    public function build_undo_payload(array $args, tool_result $result): ?array {
        if (!$result->ok || !is_array($result->data) || empty($result->data['cmid'])) {
            return null;
        }
        $previous = $result->data['previous'] ?? [];
        $undoargs = ['cmid' => (int) $result->data['cmid']];
        foreach (['name', 'intro', 'visible', 'content'] as $field) {
            if (array_key_exists($field, $previous)) {
                $undoargs[$field] = $previous[$field];
            }
        }
        return [
            'tool' => $this->get_name(),
            'args' => $undoargs,
        ];
    }

    #[\Override]
    protected function run(array $args, execution_context $ctx): tool_result {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        $cmid = (int) $args['cmid'];
        $hasname = array_key_exists('name', $args);
        $hasintro = array_key_exists('intro', $args);
        $hasvisible = array_key_exists('visible', $args);
        $hascontent = array_key_exists('content', $args);
        if (!$hasname && !$hasintro && !$hasvisible && !$hascontent) {
            return tool_result::failure('no_fields',
                get_string('tool_module_update_no_fields', 'local_ai_manager'));
        }

        $cm = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return tool_result::failure('cm_not_found',
                get_string('tool_module_not_found', 'local_ai_manager'));
        }
        $coursectx = \core\context\course::instance($cm->course);
        $modctx = \core\context\module::instance($cm->id);
        require_capability('moodle/course:manageactivities', $modctx, $ctx->user);

        $instance = $DB->get_record($cm->modname, ['id' => $cm->instance], '*', MUST_EXIST);
        if ($hascontent && $cm->modname !== 'page') {
            return tool_result::failure('content_not_supported',
                'The "content" field is only supported for mod_page activities.');
        }
        $previous = [
            'name' => (string) $instance->name,
            'intro' => isset($instance->intro) ? (string) $instance->intro : '',
            'visible' => (bool) $cm->visible,
        ];
        if ($hascontent) {
            $previous['content'] = isset($instance->content) ? (string) $instance->content : '';
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            if ($hasname || $hasintro || $hascontent) {
                $update = (object) ['id' => $instance->id];
                if ($hasname) {
                    $update->name = trim((string) $args['name']);
                }
                if ($hasintro && isset($instance->intro)) {
                    $update->intro = clean_text((string) $args['intro'], FORMAT_HTML);
                    $update->introformat = FORMAT_HTML;
                }
                if ($hascontent) {
                    $update->content = clean_text((string) $args['content'], FORMAT_HTML);
                    $update->contentformat = FORMAT_HTML;
                }
                $update->timemodified = $ctx->clock->now()->getTimestamp();
                $DB->update_record($cm->modname, $update);
                if ($hasname) {
                    $DB->set_field('course_modules', 'name', $update->name, ['id' => $cmid]);
                }
            }
            if ($hasvisible) {
                $targetvisible = (bool) $args['visible'] ? 1 : 0;
                set_coursemodule_visible($cmid, $targetvisible);
            }
            $transaction->allow_commit();
        } catch (\Throwable $t) {
            $transaction->rollback($t);
            // rollback() re-throws — unreachable, but keeps static analysis happy.
            return tool_result::failure('update_failed', $t->getMessage());
        }

        rebuild_course_cache($cm->course, true);

        return tool_result::success(
            data: [
                'cmid' => $cmid,
                'previous' => $previous,
            ],
            affected_objects: [[
                'type' => 'course_module',
                'id' => $cmid,
                'label' => $cm->modname . ' ' . $previous['name'],
            ]],
        );
    }
}

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
 * Tool: course_create (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent\tools\course;

use local_ai_manager\agent\execution_context;
use local_ai_manager\agent\tool_result;
use local_ai_manager\agent\tools\base_tool;

/**
 * Create a new course in the given category.
 *
 * Write-tool: requires explicit approval and returns an undo payload that
 * points to the delete_course operation (handled manually by an admin).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_create extends base_tool {

    #[\Override]
    public function get_name(): string {
        return 'course_create';
    }

    #[\Override]
    public function get_summary(): string {
        return get_string('tool_course_create_summary', 'local_ai_manager');
    }

    #[\Override]
    public function get_description(): string {
        return <<<EOT
Use this tool when the user wants to create a new Moodle course. Typical triggers:
"Lege einen neuen Kurs an …", "Create a course called …". If the user has not told
you in which category to create the course, first call course_list_categories and
confirm the choice with the user.

Do NOT use this tool to update an existing course (no update-course tool is
available yet). Do NOT use it to create activities inside a course — use
module_create afterwards.

Behavior: Requires {categoryid, shortname, fullname}. Optional: summary, format
(topics|weeks|social|singleactivity, default topics), numsections (1-52), visible.
Shortname must be unique site-wide; the tool fails with shortname_taken if it is
not. The tool creates the course via Moodle's create_course() API. It requires
explicit user approval and affects a shared object.

Examples:
  - "Neuer Kurs 'Mathe 7a' in Kategorie 5 mit 10 Wochen" -> course_create({categoryid:5, shortname:"mathe7a", fullname:"Mathe 7a", format:"weeks", numsections:10})
EOT;
    }

    #[\Override]
    public function get_category(): string {
        return 'course';
    }

    #[\Override]
    public function get_keywords(): array {
        return ['course', 'create', 'new', 'anlegen', 'erstellen', 'neuer kurs'];
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
        return true;
    }

    #[\Override]
    public function supports_parallel(): bool {
        return false;
    }

    #[\Override]
    public function get_required_capabilities(): array {
        return [];
    }

    #[\Override]
    public function get_parameters_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'categoryid' => [
                    'type' => 'integer',
                    'description' => 'Numeric id of the target course category.',
                    'minimum' => 1,
                ],
                'shortname' => [
                    'type' => 'string',
                    'description' => 'Unique course shortname (1-100 chars, no HTML).',
                    'minLength' => 1,
                    'maxLength' => 100,
                ],
                'fullname' => [
                    'type' => 'string',
                    'description' => 'Full human-readable course name (1-254 chars).',
                    'minLength' => 1,
                    'maxLength' => 254,
                ],
                'summary' => [
                    'type' => 'string',
                    'description' => 'Optional course summary (HTML allowed).',
                    'maxLength' => 65535,
                ],
                'format' => [
                    'type' => 'string',
                    'description' => 'Course format.',
                    'enum' => ['topics', 'weeks', 'social', 'singleactivity'],
                    'default' => 'topics',
                ],
                'numsections' => [
                    'type' => 'integer',
                    'description' => 'Number of sections (1-52). Ignored for singleactivity format.',
                    'minimum' => 1,
                    'maximum' => 52,
                    'default' => 5,
                ],
                'visible' => [
                    'type' => 'boolean',
                    'description' => 'Whether the course is visible to students after creation.',
                    'default' => true,
                ],
            ],
            'required' => ['categoryid', 'shortname', 'fullname'],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function get_result_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'courseid' => ['type' => 'integer'],
                'shortname' => ['type' => 'string'],
                'fullname' => ['type' => 'string'],
                'courseurl' => ['type' => 'string'],
            ],
        ];
    }

    #[\Override]
    public function get_affected_objects(array $args): array {
        return [[
            'type' => 'course_category',
            'id' => (int) ($args['categoryid'] ?? 0),
            'label' => 'category ' . (int) ($args['categoryid'] ?? 0),
        ]];
    }

    #[\Override]
    public function describe_for_user(array $args): string {
        return get_string('tool_course_create_describe', 'local_ai_manager', (object) [
            'shortname' => (string) ($args['shortname'] ?? ''),
            'fullname' => (string) ($args['fullname'] ?? ''),
            'categoryid' => (int) ($args['categoryid'] ?? 0),
        ]);
    }

    #[\Override]
    public function build_undo_payload(array $args, tool_result $result): ?array {
        if (!$result->ok || !is_array($result->data) || empty($result->data['courseid'])) {
            return null;
        }
        return [
            'tool' => 'course_delete',
            'args' => ['courseid' => (int) $result->data['courseid']],
            'note' => 'No automated reversal; an admin must delete the course manually.',
        ];
    }

    #[\Override]
    protected function run(array $args, execution_context $ctx): tool_result {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        $categoryid = (int) $args['categoryid'];
        $shortname = trim((string) $args['shortname']);
        $fullname = trim((string) $args['fullname']);
        $summary = (string) ($args['summary'] ?? '');
        $format = (string) ($args['format'] ?? 'topics');
        $numsections = max(1, min(52, (int) ($args['numsections'] ?? 5)));
        $visible = (bool) ($args['visible'] ?? true);

        $category = $DB->get_record('course_categories', ['id' => $categoryid]);
        if (!$category) {
            return tool_result::failure('category_not_found',
                get_string('tool_course_create_category_not_found', 'local_ai_manager'));
        }
        $catctx = \core\context\coursecat::instance($categoryid);
        require_capability('moodle/course:create', $catctx, $ctx->user);

        if ($DB->record_exists('course', ['shortname' => $shortname])) {
            return tool_result::failure('shortname_taken',
                get_string('tool_course_create_shortname_taken', 'local_ai_manager'));
        }

        $data = (object) [
            'category' => $categoryid,
            'shortname' => $shortname,
            'fullname' => $fullname,
            'summary' => $summary,
            'summaryformat' => FORMAT_HTML,
            'format' => $format,
            'visible' => $visible ? 1 : 0,
            'startdate' => $ctx->clock->now()->getTimestamp(),
        ];
        if ($format !== 'singleactivity') {
            $data->numsections = $numsections;
        }

        $course = create_course($data);

        $coursectx = \core\context\course::instance($course->id);
        $courseurl = new \moodle_url('/course/view.php', ['id' => $course->id]);

        return tool_result::success(
            data: [
                'courseid' => (int) $course->id,
                'shortname' => format_string($course->shortname, true, ['context' => $coursectx]),
                'fullname' => format_string($course->fullname, true, ['context' => $coursectx]),
                'courseurl' => $courseurl->out(false),
            ],
            affected_objects: [[
                'type' => 'course',
                'id' => (int) $course->id,
                'label' => $shortname,
            ]],
        );
    }
}

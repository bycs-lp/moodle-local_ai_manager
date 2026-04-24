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
 * Tool: course_list (MBS-10761).
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
 * Return a short list of courses the current user is enrolled in.
 *
 * Read-only, auto-approvable.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_list extends base_tool {

    #[\Override]
    public function get_name(): string {
        return 'course_list';
    }

    #[\Override]
    public function get_summary(): string {
        return get_string('tool_course_list_summary', 'local_ai_manager');
    }

    #[\Override]
    public function get_description(): string {
        return <<<EOT
Use this tool when the user asks which courses they have, wants to see their enrolled
courses, asks to pick a course or refers to "my course" / "meine Kurse" / "meinen Kurs"
without naming one explicitly.

Do NOT use this tool when the user already named a specific course by full- or
short-name — call course_get_info instead. Do NOT use it to list courses for a different
user or to enumerate the whole site; this tool is scoped to the caller.

Behavior: Returns up to `limit` enrolled courses sorted by recent access, each with
{id, shortname, fullname, visible}. Unenrolled / hidden / in-trash courses are filtered
out. The tool is idempotent and has no side effects.

Examples:
  - User: "Welche Kurse habe ich?" -> course_list({limit: 10})
  - User: "Zeig mir meine drei zuletzt besuchten Kurse" -> course_list({limit: 3})
EOT;
    }

    #[\Override]
    public function get_category(): string {
        return 'course';
    }

    #[\Override]
    public function get_keywords(): array {
        return ['course', 'enrolled', 'list', 'meine kurse', 'my courses'];
    }

    #[\Override]
    public function get_parameters_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of courses to return (1-50).',
                    'minimum' => 1,
                    'maximum' => 50,
                    'default' => 10,
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function get_result_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'courses' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'shortname' => ['type' => 'string'],
                            'fullname' => ['type' => 'string'],
                            'visible' => ['type' => 'boolean'],
                        ],
                    ],
                ],
            ],
        ];
    }

    #[\Override]
    protected function run(array $args, execution_context $ctx): tool_result {
        $limit = (int) ($args['limit'] ?? 10);
        $limit = max(1, min(50, $limit));

        $courses = enrol_get_users_courses(
            (int) $ctx->user->id,
            true,
            'id, shortname, fullname, visible',
            'visible DESC, sortorder ASC, id DESC',
        );
        $out = [];
        $count = 0;
        foreach ($courses as $course) {
            if (!$course->visible) {
                continue;
            }
            $out[] = [
                'id' => (int) $course->id,
                'shortname' => format_string($course->shortname, true,
                    ['context' => \core\context\course::instance($course->id)]),
                'fullname' => format_string($course->fullname, true,
                    ['context' => \core\context\course::instance($course->id)]),
                'visible' => (bool) $course->visible,
            ];
            if (++$count >= $limit) {
                break;
            }
        }
        return tool_result::success(['courses' => $out]);
    }
}

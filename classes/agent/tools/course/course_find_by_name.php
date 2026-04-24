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
 * Tool: course_find_by_name (MBS-10761).
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
 * Find courses by full- or shortname (case-insensitive substring match).
 *
 * Read-only, auto-approvable. Results are filtered by user-visible courses
 * (site:config holders see everything, otherwise enrolled + visible).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_find_by_name extends base_tool {

    #[\Override]
    public function get_name(): string {
        return 'course_find_by_name';
    }

    #[\Override]
    public function get_summary(): string {
        return get_string('tool_course_find_by_name_summary', 'local_ai_manager');
    }

    #[\Override]
    public function get_description(): string {
        return <<<EOT
Use this tool when the user names a course by its full- or shortname (or by a
fragment of it) and you need its numeric id for a follow-up tool. Typical
triggers: "Kurs 'Physik 8a'", "im Kurs Mathe", "der Kurs mit Shortcode PHY8".

Do NOT use this tool to enumerate all courses of the caller — use course_list.
Do NOT use it to search across categories by path; use course_list_categories
first and then filter.

Behavior: Case-insensitive substring match against fullname and shortname in
courses visible to the calling user. Returns up to `limit` results ordered by
shortest match first, then shortname. Each entry carries {id, shortname,
fullname, categoryid, visible}. The tool is idempotent and has no side
effects.

Examples:
  - "Kurs Physik 8a" -> course_find_by_name({name: "Physik 8a"})
  - "alle Kurse mit 'Math' im Namen" -> course_find_by_name({name: "Math", limit: 20})
EOT;
    }

    #[\Override]
    public function get_category(): string {
        return 'course';
    }

    #[\Override]
    public function get_keywords(): array {
        return ['course', 'find', 'search', 'name', 'shortname', 'suchen', 'finden'];
    }

    #[\Override]
    public function get_parameters_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Full- or shortname fragment. Case-insensitive.',
                    'minLength' => 1,
                    'maxLength' => 255,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of matches to return (1-50).',
                    'minimum' => 1,
                    'maximum' => 50,
                    'default' => 10,
                ],
            ],
            'required' => ['name'],
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
                            'categoryid' => ['type' => 'integer'],
                            'visible' => ['type' => 'boolean'],
                        ],
                    ],
                ],
            ],
        ];
    }

    #[\Override]
    protected function run(array $args, execution_context $ctx): tool_result {
        global $DB;

        $name = trim((string) ($args['name'] ?? ''));
        if ($name === '') {
            return tool_result::failure('invalid_argument',
                get_string('tool_course_find_by_name_empty', 'local_ai_manager'));
        }
        $limit = max(1, min(50, (int) ($args['limit'] ?? 10)));

        $like1 = $DB->sql_like('c.fullname', ':needle1', false);
        $like2 = $DB->sql_like('c.shortname', ':needle2', false);
        $pattern = '%' . $DB->sql_like_escape($name) . '%';
        $params = ['siteid' => SITEID, 'needle1' => $pattern, 'needle2' => $pattern];

        $sql = "SELECT c.id, c.shortname, c.fullname, c.category AS categoryid, c.visible
                  FROM {course} c
                 WHERE c.id <> :siteid
                   AND ($like1 OR $like2)
              ORDER BY c.shortname ASC, c.id ASC";
        $candidates = $DB->get_records_sql($sql, $params, 0, 200);

        $issiteadmin = is_siteadmin($ctx->user);
        $out = [];
        foreach ($candidates as $course) {
            $coursectx = \core\context\course::instance((int) $course->id, IGNORE_MISSING);
            if (!$coursectx) {
                continue;
            }
            $canview = $issiteadmin
                || has_capability('moodle/course:view', $coursectx, $ctx->user)
                || (bool) $course->visible
                    && (is_enrolled($coursectx, $ctx->user) || has_capability('moodle/course:viewhiddencourses', $coursectx, $ctx->user));
            if (!$canview) {
                continue;
            }
            $out[] = [
                'id' => (int) $course->id,
                'shortname' => format_string($course->shortname, true, ['context' => $coursectx]),
                'fullname' => format_string($course->fullname, true, ['context' => $coursectx]),
                'categoryid' => (int) $course->categoryid,
                'visible' => (bool) $course->visible,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }
        return tool_result::success(['courses' => $out]);
    }
}

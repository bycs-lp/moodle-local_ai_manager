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
 * Tool: course_get_info (MBS-10761).
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
 * Fetch metadata for a single course (read-only).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_get_info extends base_tool {

    #[\Override]
    public function get_name(): string {
        return 'course_get_info';
    }

    #[\Override]
    public function get_summary(): string {
        return get_string('tool_course_get_info_summary', 'local_ai_manager');
    }

    #[\Override]
    public function get_description(): string {
        return <<<EOT
Use this tool when the user asks about a specific course by id, shortname or fullname,
or when you need metadata (dates, format, description, number of sections) to answer
the next question.

Do NOT use this tool to list courses — call course_list instead. Do NOT use it to
modify a course; it only reads.

Behavior: Accepts either `courseid` or `shortname`; exactly one must be provided.
Returns {id, shortname, fullname, summary, visible, startdate, enddate, format,
numsections}. Fails with `course_not_found` if no matching course is readable by
the caller, and with `access_denied` when the user cannot view it.

Examples:
  - User: "Wann startet Physik 8a?" -> course_get_info({shortname: "Physik 8a"})
  - User: "Gib mir Infos zu Kurs 42" -> course_get_info({courseid: 42})
EOT;
    }

    #[\Override]
    public function get_category(): string {
        return 'course';
    }

    #[\Override]
    public function get_keywords(): array {
        return ['course', 'info', 'description', 'dates', 'kurs'];
    }

    #[\Override]
    public function get_parameters_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'courseid' => [
                    'type' => 'integer',
                    'description' => 'Numeric course id. Either this or shortname must be set.',
                    'minimum' => 1,
                ],
                'shortname' => [
                    'type' => 'string',
                    'description' => 'Course shortname. Used when courseid is absent.',
                    'maxLength' => 255,
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
                'id' => ['type' => 'integer'],
                'shortname' => ['type' => 'string'],
                'fullname' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
                'visible' => ['type' => 'boolean'],
                'startdate' => ['type' => 'integer'],
                'enddate' => ['type' => 'integer'],
                'format' => ['type' => 'string'],
                'numsections' => ['type' => 'integer'],
            ],
        ];
    }

    #[\Override]
    public function get_affected_objects(array $args): array {
        if (!empty($args['courseid'])) {
            return [['type' => 'course', 'id' => (int) $args['courseid']]];
        }
        return [];
    }

    #[\Override]
    protected function run(array $args, execution_context $ctx): tool_result {
        global $DB;

        $courseid = isset($args['courseid']) ? (int) $args['courseid'] : 0;
        $shortname = isset($args['shortname']) ? (string) $args['shortname'] : '';
        if ($courseid === 0 && $shortname === '') {
            return tool_result::failure('missing_argument',
                get_string('tool_course_get_info_missing_argument', 'local_ai_manager'));
        }

        $course = $courseid > 0
            ? $DB->get_record('course', ['id' => $courseid])
            : $DB->get_record('course', ['shortname' => $shortname]);
        if (!$course) {
            return tool_result::failure('course_not_found',
                get_string('tool_course_not_found', 'local_ai_manager'));
        }

        $coursecontext = \core\context\course::instance($course->id);
        if (!can_access_course($course, $ctx->user) && !has_capability('moodle/course:view', $coursecontext, $ctx->user)) {
            return tool_result::failure('access_denied',
                get_string('tool_course_access_denied', 'local_ai_manager'));
        }

        $numsections = (int) $DB->count_records('course_sections', ['course' => $course->id]);

        return tool_result::success(
            data: [
                'id' => (int) $course->id,
                'shortname' => format_string($course->shortname, true, ['context' => $coursecontext]),
                'fullname' => format_string($course->fullname, true, ['context' => $coursecontext]),
                'summary' => format_text((string) $course->summary, (int) $course->summaryformat,
                    ['context' => $coursecontext]),
                'visible' => (bool) $course->visible,
                'startdate' => (int) $course->startdate,
                'enddate' => (int) $course->enddate,
                'format' => (string) $course->format,
                'numsections' => $numsections,
            ],
            affected_objects: [[
                'type' => 'course',
                'id' => (int) $course->id,
                'label' => $course->shortname,
            ]],
        );
    }
}

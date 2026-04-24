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
 * Tool: module_list (MBS-10761).
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
 * List activities (course modules) of a course, optionally filtered by section.
 *
 * Read-only, auto-approvable.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class module_list extends base_tool {

    #[\Override]
    public function get_name(): string {
        return 'module_list';
    }

    #[\Override]
    public function get_summary(): string {
        return get_string('tool_module_list_summary', 'local_ai_manager');
    }

    #[\Override]
    public function get_description(): string {
        return <<<EOT
Use this tool when the user wants to know which activities or resources a course
contains, or to locate a specific activity by name before calling module_update /
module_delete. Typical triggers: "Welche Aktivitäten hat Kurs X?", "Zeig mir alle
Foren in Kurs 42".

Do NOT use this tool to list courses (use course_list) or to list question
categories (use question_category_list).

Behavior: Requires `courseid`. Optional: `section` (only that section),
`modname` (filter by module type, e.g. 'quiz'), `limit` (1-200, default 100).
Returns {cmid, modname, instanceid, name, section, sectionname, visible, url} per
activity. Ordered by section then sequence.

Examples:
  - "Aktivitäten in Kurs 42" -> module_list({courseid:42})
  - "Foren in Kurs 42" -> module_list({courseid:42, modname:"forum"})
EOT;
    }

    #[\Override]
    public function get_category(): string {
        return 'mod';
    }

    #[\Override]
    public function get_keywords(): array {
        return ['module', 'activity', 'activities', 'aktivität', 'aktivitäten', 'cm', 'list'];
    }

    #[\Override]
    public function get_parameters_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'courseid' => [
                    'type' => 'integer',
                    'description' => 'Numeric course id.',
                    'minimum' => 1,
                ],
                'section' => [
                    'type' => 'integer',
                    'description' => 'Optional section number. Omit to list the whole course.',
                    'minimum' => 0,
                ],
                'modname' => [
                    'type' => 'string',
                    'description' => 'Optional module type filter, e.g. "forum", "quiz", "page".',
                    'maxLength' => 40,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of activities to return (1-200).',
                    'minimum' => 1,
                    'maximum' => 200,
                    'default' => 100,
                ],
            ],
            'required' => ['courseid'],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function get_result_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'activities' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'cmid' => ['type' => 'integer'],
                            'modname' => ['type' => 'string'],
                            'instanceid' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                            'section' => ['type' => 'integer'],
                            'sectionname' => ['type' => 'string'],
                            'visible' => ['type' => 'boolean'],
                            'url' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }

    #[\Override]
    protected function run(array $args, execution_context $ctx): tool_result {
        global $DB;
        $courseid = (int) $args['courseid'];
        $section = isset($args['section']) ? (int) $args['section'] : null;
        $modname = isset($args['modname']) ? trim((string) $args['modname']) : '';
        $limit = max(1, min(200, (int) ($args['limit'] ?? 100)));

        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            return tool_result::failure('course_not_found',
                get_string('tool_course_not_found', 'local_ai_manager'));
        }
        $coursectx = \core\context\course::instance($courseid);
        require_capability('moodle/course:view', $coursectx, $ctx->user);

        $modinfo = get_fast_modinfo($course, (int) $ctx->user->id);
        $activities = [];
        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            if ($section !== null && (int) $cm->sectionnum !== $section) {
                continue;
            }
            if ($modname !== '' && $cm->modname !== $modname) {
                continue;
            }
            $sectioninfo = $modinfo->get_section_info($cm->sectionnum);
            $sectionname = $sectioninfo
                ? ($sectioninfo->name ?? get_section_name($course, $sectioninfo))
                : '';
            $activities[] = [
                'cmid' => (int) $cm->id,
                'modname' => (string) $cm->modname,
                'instanceid' => (int) $cm->instance,
                'name' => format_string($cm->name, true, ['context' => $cm->context]),
                'section' => (int) $cm->sectionnum,
                'sectionname' => (string) $sectionname,
                'visible' => (bool) $cm->visible,
                'url' => $cm->url ? $cm->url->out(false) : '',
            ];
            if (count($activities) >= $limit) {
                break;
            }
        }
        return tool_result::success(['activities' => $activities]);
    }
}

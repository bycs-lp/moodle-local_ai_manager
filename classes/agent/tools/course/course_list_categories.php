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
 * Tool: course_list_categories (MBS-10761).
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
 * List course categories the calling user can see / create courses in.
 *
 * Read-only, auto-approvable. Primarily used to let the LLM pick a valid
 * categoryid before calling course_create.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_list_categories extends base_tool {

    #[\Override]
    public function get_name(): string {
        return 'course_list_categories';
    }

    #[\Override]
    public function get_summary(): string {
        return get_string('tool_course_list_categories_summary', 'local_ai_manager');
    }

    #[\Override]
    public function get_description(): string {
        return <<<EOT
Use this tool when the user wants to see course categories, or before calling
course_create to look up a valid categoryid. Typical triggers: "Welche Kategorien
gibt es?", "In welche Kategorie kann ich einen Kurs anlegen?".

Do NOT use this tool to search for existing courses (use course_list or
course_get_info). Do NOT use it to modify categories.

Behavior: Returns up to `limit` categories visible to the caller, each with
{id, name, idnumber, parent, visible, coursecount, can_create_course}. Results are
ordered by sortorder. Categories the user has no visibility for are filtered out.
The tool is idempotent and has no side effects.

Examples:
  - User: "In welche Kategorie kann ich einen Kurs anlegen?" -> course_list_categories({only_createable:true})
  - User: "Liste mir die Kurskategorien" -> course_list_categories({limit:20})
EOT;
    }

    #[\Override]
    public function get_category(): string {
        return 'course';
    }

    #[\Override]
    public function get_keywords(): array {
        return ['category', 'categories', 'kategorie', 'kategorien', 'course'];
    }

    #[\Override]
    public function get_parameters_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of categories to return (1-200).',
                    'minimum' => 1,
                    'maximum' => 200,
                    'default' => 50,
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Optional case-insensitive substring to filter by name.',
                    'maxLength' => 200,
                ],
                'only_createable' => [
                    'type' => 'boolean',
                    'description' => 'If true, only return categories the user may create courses in.',
                    'default' => false,
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
                'categories' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                            'idnumber' => ['type' => 'string'],
                            'parent' => ['type' => 'integer'],
                            'visible' => ['type' => 'boolean'],
                            'coursecount' => ['type' => 'integer'],
                            'can_create_course' => ['type' => 'boolean'],
                        ],
                    ],
                ],
            ],
        ];
    }

    #[\Override]
    protected function run(array $args, execution_context $ctx): tool_result {
        global $DB;
        $limit = max(1, min(200, (int) ($args['limit'] ?? 50)));
        $search = trim((string) ($args['search'] ?? ''));
        $onlycreateable = (bool) ($args['only_createable'] ?? false);

        $select = 'visible = 1';
        $params = [];
        if ($search !== '') {
            $like = $DB->sql_like('name', ':search', false);
            $select .= ' AND ' . $like;
            $params['search'] = '%' . $DB->sql_like_escape($search) . '%';
        }
        $records = $DB->get_records_select('course_categories', $select, $params,
            'sortorder ASC, id ASC', '*', 0, $limit * 4);

        $out = [];
        foreach ($records as $rec) {
            $catctx = \core\context\coursecat::instance($rec->id, IGNORE_MISSING);
            if (!$catctx) {
                continue;
            }
            if (!has_capability('moodle/category:viewcourselist', $catctx, $ctx->user)) {
                continue;
            }
            $cancreate = has_capability('moodle/course:create', $catctx, $ctx->user);
            if ($onlycreateable && !$cancreate) {
                continue;
            }
            $out[] = [
                'id' => (int) $rec->id,
                'name' => format_string($rec->name, true, ['context' => $catctx]),
                'idnumber' => (string) $rec->idnumber,
                'parent' => (int) $rec->parent,
                'visible' => (bool) $rec->visible,
                'coursecount' => (int) $rec->coursecount,
                'can_create_course' => $cancreate,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }
        return tool_result::success(['categories' => $out]);
    }
}

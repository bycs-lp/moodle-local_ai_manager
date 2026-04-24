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
 * Tool: question_category_list (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent\tools\question;

use local_ai_manager\agent\execution_context;
use local_ai_manager\agent\tool_result;
use local_ai_manager\agent\tools\base_tool;

/**
 * List question categories reachable from a course.
 *
 * Walks the course's qbank modules (Moodle 5.x question bank reform) and
 * returns their non-top question categories. Read-only, auto-approvable.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_category_list extends base_tool {

    #[\Override]
    public function get_name(): string {
        return 'question_category_list';
    }

    #[\Override]
    public function get_summary(): string {
        return get_string('tool_question_category_list_summary', 'local_ai_manager');
    }

    #[\Override]
    public function get_description(): string {
        return <<<EOT
Use this tool when the user wants to know which question categories are
available in a course, or before calling question_create / question_category_create
to pick a valid category or qbank module. Typical triggers: "Welche Fragenkategorien
gibt es in Kurs 42?", "Liste die Fragensammlungen".

Do NOT use this tool to list questions themselves (not yet supported) or to
list site-wide categories (scope is one course).

Behavior: Requires `courseid`. Walks all qbank module instances of the course,
returns {id, name, qbank_cmid, qbank_name, parent, info, questioncount} per
category. The "top" category of each qbank is included and flagged with
is_top:true.

Examples:
  - "Kategorien in Kurs 42" -> question_category_list({courseid:42})
EOT;
    }

    #[\Override]
    public function get_category(): string {
        return 'question';
    }

    #[\Override]
    public function get_keywords(): array {
        return ['question', 'category', 'categories', 'fragenkategorie', 'fragensammlung', 'qbank'];
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
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of categories to return (1-500).',
                    'minimum' => 1,
                    'maximum' => 500,
                    'default' => 200,
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
                'categories' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                            'info' => ['type' => 'string'],
                            'parent' => ['type' => 'integer'],
                            'qbank_cmid' => ['type' => 'integer'],
                            'qbank_name' => ['type' => 'string'],
                            'is_top' => ['type' => 'boolean'],
                            'questioncount' => ['type' => 'integer'],
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
        $limit = max(1, min(500, (int) ($args['limit'] ?? 200)));

        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            return tool_result::failure('course_not_found',
                get_string('tool_course_not_found', 'local_ai_manager'));
        }
        $coursectx = \core\context\course::instance($courseid);
        require_capability('moodle/course:view', $coursectx, $ctx->user);

        $modinfo = get_fast_modinfo($course, (int) $ctx->user->id);
        $out = [];
        foreach ($modinfo->get_instances_of('qbank') as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            $qbankctx = \core\context\module::instance($cm->id);
            if (!has_capability('moodle/question:viewall', $qbankctx, $ctx->user)
                && !has_capability('moodle/question:viewmine', $qbankctx, $ctx->user)) {
                continue;
            }
            $cats = $DB->get_records('question_categories', ['contextid' => $qbankctx->id],
                'sortorder ASC, id ASC');
            foreach ($cats as $cat) {
                $count = $DB->count_records_sql(
                    "SELECT COUNT(q.id)
                       FROM {question} q
                       JOIN {question_versions} qv ON qv.questionid = q.id
                       JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                      WHERE qbe.questioncategoryid = :catid",
                    ['catid' => $cat->id]);
                $out[] = [
                    'id' => (int) $cat->id,
                    'name' => format_string($cat->name, true, ['context' => $qbankctx]),
                    'info' => (string) $cat->info,
                    'parent' => (int) $cat->parent,
                    'qbank_cmid' => (int) $cm->id,
                    'qbank_name' => format_string($cm->name, true, ['context' => $qbankctx]),
                    'is_top' => ((int) $cat->parent === 0),
                    'questioncount' => (int) $count,
                ];
                if (count($out) >= $limit) {
                    break 2;
                }
            }
        }
        return tool_result::success(['categories' => $out]);
    }
}

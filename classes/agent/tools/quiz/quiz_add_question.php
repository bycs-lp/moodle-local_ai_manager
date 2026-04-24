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
 * Tool: quiz_add_question (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent\tools\quiz;

use local_ai_manager\agent\execution_context;
use local_ai_manager\agent\tool_result;
use local_ai_manager\agent\tools\base_tool;

/**
 * Add an existing question from the question bank to a quiz activity.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_add_question extends base_tool {

    #[\Override]
    public function get_name(): string {
        return 'quiz_add_question';
    }

    #[\Override]
    public function get_summary(): string {
        return get_string('tool_quiz_add_question_summary', 'local_ai_manager');
    }

    #[\Override]
    public function get_description(): string {
        return <<<EOT
Use this tool to attach an existing question (from the question bank) to an
existing quiz. Typical triggers: "Füge Frage 123 zum Quiz cmid 55 hinzu",
"Add question 123 to the quiz".

Do NOT use this tool to create a new question (use question_create first).
Do NOT use this tool for random questions — that path is deprecated.

Behavior: Requires {quiz_cmid, questionid}. Optional page (default 0 =
append) and maxmark (default = question defaultmark). Requires explicit
approval.

Examples:
  - "Füge Frage 123 zum Quiz 55 hinzu" -> quiz_add_question({quiz_cmid:55, questionid:123})
EOT;
    }

    #[\Override]
    public function get_category(): string {
        return 'quiz';
    }

    #[\Override]
    public function get_keywords(): array {
        return ['quiz', 'question', 'add', 'attach', 'hinzufügen', 'test'];
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
    public function get_parameters_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'quiz_cmid' => [
                    'type' => 'integer',
                    'description' => 'Course module id of the target quiz.',
                    'minimum' => 1,
                ],
                'questionid' => [
                    'type' => 'integer',
                    'description' => 'Id of the question to add.',
                    'minimum' => 1,
                ],
                'page' => [
                    'type' => 'integer',
                    'description' => 'Quiz page to place the question on (0 = append to last page).',
                    'minimum' => 0,
                    'default' => 0,
                ],
                'maxmark' => [
                    'type' => 'number',
                    'description' => 'Optional max mark for this slot. Defaults to the question default mark.',
                    'minimum' => 0,
                ],
            ],
            'required' => ['quiz_cmid', 'questionid'],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function get_result_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'quiz_cmid' => ['type' => 'integer'],
                'quizid' => ['type' => 'integer'],
                'questionid' => ['type' => 'integer'],
                'added' => ['type' => 'boolean'],
            ],
        ];
    }

    #[\Override]
    public function get_affected_objects(array $args): array {
        return [[
            'type' => 'quiz',
            'id' => (int) ($args['quiz_cmid'] ?? 0),
            'label' => 'quiz cm ' . (int) ($args['quiz_cmid'] ?? 0),
        ]];
    }

    #[\Override]
    public function describe_for_user(array $args): string {
        return get_string('tool_quiz_add_question_describe', 'local_ai_manager', (object) [
            'quiz_cmid' => (int) ($args['quiz_cmid'] ?? 0),
            'questionid' => (int) ($args['questionid'] ?? 0),
        ]);
    }

    #[\Override]
    public function build_undo_payload(array $args, tool_result $result): ?array {
        if (!$result->ok || !is_array($result->data) || empty($result->data['quizid'])) {
            return null;
        }
        return [
            'tool' => 'quiz_remove_question',
            'args' => [
                'quiz_cmid' => (int) ($result->data['quiz_cmid'] ?? 0),
                'questionid' => (int) ($result->data['questionid'] ?? 0),
            ],
            'note' => 'No automated reversal; remove the question via the quiz editing UI.',
        ];
    }

    #[\Override]
    protected function run(array $args, execution_context $ctx): tool_result {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $cmid = (int) $args['quiz_cmid'];
        $questionid = (int) $args['questionid'];
        $page = (int) ($args['page'] ?? 0);
        $maxmark = isset($args['maxmark']) ? (float) $args['maxmark'] : null;

        $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return tool_result::failure('quiz_not_found',
                get_string('tool_quiz_not_found', 'local_ai_manager'));
        }
        $modctx = \core\context\module::instance($cm->id);
        require_capability('mod/quiz:manage', $modctx, $ctx->user);

        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $quiz->cmid = $cm->id;

        $question = $DB->get_record('question', ['id' => $questionid], 'id, qtype, name', IGNORE_MISSING);
        if (!$question) {
            return tool_result::failure('question_not_found',
                get_string('tool_question_not_found', 'local_ai_manager'));
        }
        if ($question->qtype === 'random') {
            return tool_result::failure('random_not_supported',
                get_string('tool_quiz_add_question_random_not_supported', 'local_ai_manager'));
        }

        $added = quiz_add_quiz_question($questionid, $quiz, $page, $maxmark);

        return tool_result::success(
            data: [
                'quiz_cmid' => $cmid,
                'quizid' => (int) $quiz->id,
                'questionid' => $questionid,
                'added' => (bool) $added,
            ],
            affected_objects: [
                ['type' => 'quiz', 'id' => (int) $quiz->id, 'label' => $quiz->name],
                ['type' => 'question', 'id' => $questionid, 'label' => $question->name],
            ],
        );
    }
}

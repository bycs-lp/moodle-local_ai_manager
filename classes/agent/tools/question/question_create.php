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
 * Tool: question_create (MBS-10761).
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
 * Create a question in a given question category.
 *
 * Supports the six most common qtypes: multichoice, truefalse, shortanswer,
 * essay, numerical and matching. Write-tool, requires approval.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_create extends base_tool {

    /** @var string[] Supported qtype names. */
    private const SUPPORTED_QTYPES = [
        'multichoice', 'truefalse', 'shortanswer', 'essay', 'numerical', 'match',
    ];

    #[\Override]
    public function get_name(): string {
        return 'question_create';
    }

    #[\Override]
    public function get_summary(): string {
        return get_string('tool_question_create_summary', 'local_ai_manager');
    }

    #[\Override]
    public function get_description(): string {
        return <<<EOT
Use this tool to create a new question in an existing question category.
Supported qtypes: multichoice, truefalse, shortanswer, essay, numerical,
match. Typical triggers: "Erstelle eine Multiple-Choice-Frage", "Neue
wahr/falsch-Frage in Kategorie 42".

Do NOT use this tool for qtypes outside the list above (calculated, cloze,
drag-and-drop, aitext and similar are not supported yet). Do NOT use this tool
to add the created question to a quiz — call quiz_add_question afterwards if
needed.

Behavior: Requires {categoryid, qtype, name, questiontext} plus a qtype-specific
payload under `qtype_data`. Each qtype has its own expected shape — see
parameter schema. Requires explicit approval.

qtype_data shapes:
- truefalse: {correctanswer: bool}
- multichoice: {single: bool, choices: [{text:string, correct:bool, feedback?:string}, ...]} — at least 2, exactly one correct if single=true
- shortanswer: {usecase: bool, answers: [{pattern:string, fraction:number 0..1, feedback?:string}, ...]}
- essay: {responseformat: "editor"|"editorfilepicker"|"plain"|"monospaced"|"noinline", responsefieldlines?:int}
- numerical: {answers: [{value:number|string, tolerance:number, fraction:number 0..1, feedback?:string}, ...]}
- match: {pairs: [{stem:string, answer:string}, ...]} — at least 3 pairs

Examples:
  - "Neue wahr/falsch-Frage" ->
    question_create({categoryid:42, qtype:"truefalse", name:"Q1",
                     questiontext:"<p>Ist Moodle Open-Source?</p>",
                     qtype_data:{correctanswer:true}})
EOT;
    }

    #[\Override]
    public function get_category(): string {
        return 'question';
    }

    #[\Override]
    public function get_keywords(): array {
        return ['question', 'create', 'multichoice', 'truefalse', 'shortanswer', 'essay',
            'numerical', 'matching', 'frage', 'erstellen'];
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
                'categoryid' => [
                    'type' => 'integer',
                    'description' => 'Target question category id.',
                    'minimum' => 1,
                ],
                'qtype' => [
                    'type' => 'string',
                    'description' => 'Question type. One of the supported types.',
                    'enum' => self::SUPPORTED_QTYPES,
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Question name / short title (1-255 chars).',
                    'minLength' => 1,
                    'maxLength' => 255,
                ],
                'questiontext' => [
                    'type' => 'string',
                    'description' => 'HTML body of the question stem.',
                    'minLength' => 1,
                ],
                'defaultmark' => [
                    'type' => 'number',
                    'description' => 'Default mark (default 1.0).',
                    'minimum' => 0,
                    'default' => 1.0,
                ],
                'generalfeedback' => [
                    'type' => 'string',
                    'description' => 'Optional general feedback shown after the question is answered.',
                ],
                'qtype_data' => [
                    'type' => 'object',
                    'description' => 'Qtype-specific payload. See description for per-qtype shape.',
                ],
            ],
            'required' => ['categoryid', 'qtype', 'name', 'questiontext', 'qtype_data'],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function get_result_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'questionid' => ['type' => 'integer'],
                'qtype' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'categoryid' => ['type' => 'integer'],
            ],
        ];
    }

    #[\Override]
    public function get_affected_objects(array $args): array {
        return [[
            'type' => 'question_category',
            'id' => (int) ($args['categoryid'] ?? 0),
            'label' => 'category ' . (int) ($args['categoryid'] ?? 0),
        ]];
    }

    #[\Override]
    public function describe_for_user(array $args): string {
        return get_string('tool_question_create_describe', 'local_ai_manager', (object) [
            'qtype' => (string) ($args['qtype'] ?? ''),
            'name' => (string) ($args['name'] ?? ''),
            'categoryid' => (int) ($args['categoryid'] ?? 0),
        ]);
    }

    #[\Override]
    public function build_undo_payload(array $args, tool_result $result): ?array {
        if (!$result->ok || !is_array($result->data) || empty($result->data['questionid'])) {
            return null;
        }
        return [
            'tool' => 'question_delete',
            'args' => ['questionid' => (int) $result->data['questionid']],
            'note' => 'No automated reversal; delete the question manually in the question bank UI.',
        ];
    }

    #[\Override]
    protected function run(array $args, execution_context $ctx): tool_result {
        global $CFG, $DB;
        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/question/engine/bank.php');

        $catid = (int) $args['categoryid'];
        $qtype = (string) $args['qtype'];
        $name = trim((string) $args['name']);
        $questiontext = (string) $args['questiontext'];
        $defaultmark = (float) ($args['defaultmark'] ?? 1.0);
        $generalfeedback = (string) ($args['generalfeedback'] ?? '');
        $qtypedata = (array) ($args['qtype_data'] ?? []);

        if (!in_array($qtype, self::SUPPORTED_QTYPES, true)) {
            return tool_result::failure('unsupported_qtype',
                get_string('tool_question_create_unsupported_qtype', 'local_ai_manager', $qtype));
        }
        $cat = $DB->get_record('question_categories', ['id' => $catid]);
        if (!$cat) {
            return tool_result::failure('category_not_found',
                get_string('tool_question_category_not_found', 'local_ai_manager'));
        }
        $ctxrecord = \core\context::instance_by_id((int) $cat->contextid);
        require_capability('moodle/question:add', $ctxrecord, $ctx->user);

        // Build $fromform.
        $fromform = new \stdClass();
        $fromform->category = $catid . ',' . $cat->contextid;
        $fromform->name = $name;
        $fromform->questiontext = ['text' => clean_text($questiontext, FORMAT_HTML), 'format' => FORMAT_HTML];
        $fromform->generalfeedback = ['text' => clean_text($generalfeedback, FORMAT_HTML), 'format' => FORMAT_HTML];
        $fromform->defaultmark = $defaultmark;
        $fromform->penalty = 0.3333333;
        $fromform->status = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;
        $fromform->hint = [];
        $fromform->hintformat = [];

        try {
            $this->fill_qtype_specific($fromform, $qtype, $qtypedata);
        } catch (\invalid_parameter_exception $e) {
            return tool_result::failure('invalid_qtype_data', $e->getMessage());
        }

        $question = new \stdClass();
        $question->category = $catid;
        $question->qtype = $qtype;
        $question->createdby = (int) $ctx->user->id;
        $question->modifiedby = (int) $ctx->user->id;
        $question->idnumber = null;
        $question->status = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;

        $question = \question_bank::get_qtype($qtype)->save_question($question, $fromform);

        return tool_result::success(
            data: [
                'questionid' => (int) $question->id,
                'qtype' => $qtype,
                'name' => $name,
                'categoryid' => $catid,
            ],
            affected_objects: [[
                'type' => 'question',
                'id' => (int) $question->id,
                'label' => $qtype . ': ' . $name,
            ]],
        );
    }

    /**
     * Populate qtype-specific form fields.
     *
     * @param \stdClass $form The form object to populate.
     * @param string $qtype Question type.
     * @param array $data User-supplied qtype_data payload.
     * @throws \invalid_parameter_exception On malformed data.
     */
    private function fill_qtype_specific(\stdClass $form, string $qtype, array $data): void {
        match ($qtype) {
            'truefalse' => $this->fill_truefalse($form, $data),
            'multichoice' => $this->fill_multichoice($form, $data),
            'shortanswer' => $this->fill_shortanswer($form, $data),
            'essay' => $this->fill_essay($form, $data),
            'numerical' => $this->fill_numerical($form, $data),
            'match' => $this->fill_match($form, $data),
        };
    }

    /**
     * @param \stdClass $form
     * @param array $data
     */
    private function fill_truefalse(\stdClass $form, array $data): void {
        if (!array_key_exists('correctanswer', $data)) {
            throw new \invalid_parameter_exception('truefalse requires correctanswer (bool).');
        }
        $form->correctanswer = !empty($data['correctanswer']) ? '1' : '0';
        $form->feedbacktrue = ['text' => '', 'format' => FORMAT_HTML];
        $form->feedbackfalse = ['text' => '', 'format' => FORMAT_HTML];
        $form->penalty = 1;
    }

    /**
     * @param \stdClass $form
     * @param array $data
     */
    private function fill_multichoice(\stdClass $form, array $data): void {
        $choices = $data['choices'] ?? [];
        if (!is_array($choices) || count($choices) < 2) {
            throw new \invalid_parameter_exception('multichoice requires at least 2 choices.');
        }
        $single = !empty($data['single'] ?? true) ? 1 : 0;
        $correctcount = 0;
        foreach ($choices as $c) {
            if (!empty($c['correct'])) {
                $correctcount++;
            }
        }
        if ($single && $correctcount !== 1) {
            throw new \invalid_parameter_exception('single=true requires exactly one correct choice.');
        }
        if (!$single && $correctcount < 1) {
            throw new \invalid_parameter_exception('multichoice requires at least one correct choice.');
        }
        $form->single = (string) $single;
        $form->shuffleanswers = 1;
        $form->answernumbering = 'abc';
        $form->showstandardinstruction = 0;
        $form->noanswers = count($choices);
        $form->answer = [];
        $form->fraction = [];
        $form->feedback = [];
        foreach (array_values($choices) as $i => $c) {
            $form->answer[$i] = ['text' => (string) ($c['text'] ?? ''), 'format' => FORMAT_HTML];
            if ($single) {
                $form->fraction[$i] = !empty($c['correct']) ? '1.0' : '0.0';
            } else {
                $share = $correctcount > 0 ? 1.0 / $correctcount : 0.0;
                $form->fraction[$i] = !empty($c['correct']) ? (string) $share : '0.0';
            }
            $form->feedback[$i] = ['text' => (string) ($c['feedback'] ?? ''), 'format' => FORMAT_HTML];
        }
        $this->apply_combined_feedback($form);
        $form->shownumcorrect = 1;
        $form->numhints = 0;
    }

    /**
     * @param \stdClass $form
     * @param array $data
     */
    private function fill_shortanswer(\stdClass $form, array $data): void {
        $answers = $data['answers'] ?? [];
        if (!is_array($answers) || count($answers) < 1) {
            throw new \invalid_parameter_exception('shortanswer requires at least 1 answer.');
        }
        $form->usecase = !empty($data['usecase']) ? 1 : 0;
        $form->answer = [];
        $form->fraction = [];
        $form->feedback = [];
        foreach (array_values($answers) as $i => $a) {
            if (!isset($a['pattern']) || $a['pattern'] === '') {
                throw new \invalid_parameter_exception('shortanswer answer requires non-empty pattern.');
            }
            $form->answer[$i] = (string) $a['pattern'];
            $fraction = (float) ($a['fraction'] ?? 1.0);
            $form->fraction[$i] = number_format($fraction, 7, '.', '');
            $form->feedback[$i] = ['text' => (string) ($a['feedback'] ?? ''), 'format' => FORMAT_HTML];
        }
    }

    /**
     * @param \stdClass $form
     * @param array $data
     */
    private function fill_essay(\stdClass $form, array $data): void {
        $allowed = ['editor', 'editorfilepicker', 'plain', 'monospaced', 'noinline'];
        $responseformat = (string) ($data['responseformat'] ?? 'editor');
        if (!in_array($responseformat, $allowed, true)) {
            throw new \invalid_parameter_exception('essay responseformat must be one of: ' . implode(', ', $allowed));
        }
        $form->responseformat = $responseformat;
        $form->responserequired = 1;
        $form->responsefieldlines = (int) ($data['responsefieldlines'] ?? 10);
        $form->attachments = 0;
        $form->attachmentsrequired = 0;
        $form->maxbytes = 0;
        $form->filetypeslist = '';
        $form->graderinfo = ['text' => '', 'format' => FORMAT_HTML];
        $form->responsetemplate = ['text' => '', 'format' => FORMAT_HTML];
        $form->minwordlimit = 0;
        $form->maxwordlimit = 0;
    }

    /**
     * @param \stdClass $form
     * @param array $data
     */
    private function fill_numerical(\stdClass $form, array $data): void {
        $answers = $data['answers'] ?? [];
        if (!is_array($answers) || count($answers) < 1) {
            throw new \invalid_parameter_exception('numerical requires at least 1 answer.');
        }
        $form->noanswers = count($answers);
        $form->answer = [];
        $form->tolerance = [];
        $form->fraction = [];
        $form->feedback = [];
        foreach (array_values($answers) as $i => $a) {
            if (!array_key_exists('value', $a)) {
                throw new \invalid_parameter_exception('numerical answer requires a value.');
            }
            $form->answer[$i] = (string) $a['value'];
            $form->tolerance[$i] = (string) ($a['tolerance'] ?? 0);
            $fraction = (float) ($a['fraction'] ?? 1.0);
            $form->fraction[$i] = number_format($fraction, 7, '.', '');
            $form->feedback[$i] = ['text' => (string) ($a['feedback'] ?? ''), 'format' => FORMAT_HTML];
        }
        $form->unitrole = '3';
        $form->unitpenalty = 0.1;
        $form->unitgradingtypes = '1';
        $form->unitsleft = '0';
        $form->nounits = 1;
        $form->multiplier = ['1.0'];
        $form->numhints = 0;
    }

    /**
     * @param \stdClass $form
     * @param array $data
     */
    private function fill_match(\stdClass $form, array $data): void {
        $pairs = $data['pairs'] ?? [];
        if (!is_array($pairs) || count($pairs) < 3) {
            throw new \invalid_parameter_exception('match requires at least 3 pairs.');
        }
        $form->shuffleanswers = 1;
        $this->apply_combined_feedback($form);
        $form->shownumcorrect = 1;
        $form->subquestions = [];
        $form->subanswers = [];
        foreach (array_values($pairs) as $i => $p) {
            if (!isset($p['stem'], $p['answer'])) {
                throw new \invalid_parameter_exception('match pair requires stem and answer.');
            }
            $form->subquestions[$i] = ['text' => (string) $p['stem'], 'format' => FORMAT_HTML];
            $form->subanswers[$i] = (string) $p['answer'];
        }
        $form->noanswers = count($pairs);
        $form->numhints = 0;
    }

    /**
     * Apply combined-feedback form fields shared by multichoice and match.
     *
     * @param \stdClass $form
     */
    private function apply_combined_feedback(\stdClass $form): void {
        $form->correctfeedback = ['text' => get_string('correctfeedbackdefault', 'question'), 'format' => FORMAT_HTML];
        $form->partiallycorrectfeedback = ['text' => get_string('partiallycorrectfeedbackdefault', 'question'),
            'format' => FORMAT_HTML];
        $form->incorrectfeedback = ['text' => get_string('incorrectfeedbackdefault', 'question'), 'format' => FORMAT_HTML];
    }
}

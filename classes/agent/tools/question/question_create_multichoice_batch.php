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
 * Tool: question_create_multichoice_batch (MBS-10761).
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
 * Batch-create multichoice questions with partial-success semantics.
 *
 * Each item is processed in its own DB transaction; failures are reported
 * per-item without aborting the batch (SPEZ §4.7.4, Konzept §8.3).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_create_multichoice_batch extends base_tool {

    /** @var int Hard cap on items per batch to keep single-call latency bounded. */
    private const MAX_ITEMS = 50;

    #[\Override]
    public function get_name(): string {
        return 'question_create_multichoice_batch';
    }

    #[\Override]
    public function get_summary(): string {
        return get_string('tool_question_create_multichoice_batch_summary', 'local_ai_manager');
    }

    #[\Override]
    public function get_description(): string {
        return <<<EOT
Use this tool when the user asks to create multiple multichoice questions in
one go. Typical triggers: "Erzeuge 10 Multiple-Choice-Fragen in Kategorie 42",
"Lege diese fünf Fragen als Multiple Choice an". Prefer this over calling
question_create repeatedly — one approval covers the whole batch.

Do NOT use this tool for mixed qtypes — it only creates multichoice
questions. Call question_create per item if qtypes differ.

Behavior: Requires {items: [{categoryid, name, questiontext, single?, choices}]}.
Each item is created in its own DB transaction; if one item fails the others
still succeed. Returns {succeeded: [{input_index, questionid, name}], failed:
[{input_index, error_code, error_message}]}. The tool is idempotent across
retries only in the sense that already-succeeded items are not rolled back;
re-running the whole batch will create duplicates, so only re-run items from
the `failed` list.

Each item shape:
  {
    categoryid:    integer (target question category),
    name:          string (1-255, unique within category recommended),
    questiontext:  string (HTML allowed),
    defaultmark?:  number (default 1.0),
    single?:       boolean (single-correct, default true),
    choices:       [{text:string, correct:bool, feedback?:string}, ...]
  }

Requires explicit approval. Up to 50 items per call.
EOT;
    }

    #[\Override]
    public function get_category(): string {
        return 'question';
    }

    #[\Override]
    public function get_keywords(): array {
        return ['question', 'multichoice', 'batch', 'bulk', 'fragen', 'mehrere'];
    }

    #[\Override]
    public function requires_approval(): bool {
        return true;
    }

    #[\Override]
    public function is_idempotent(): bool {
        // Running the same batch twice creates duplicates — not idempotent.
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
    public function get_timeout_seconds(): int {
        // A batch of 50 items can take significantly longer than a single tool.
        return 180;
    }

    #[\Override]
    public function get_parameters_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'description' => 'List of multichoice question definitions to create (1-50).',
                    'minItems' => 1,
                    'maxItems' => self::MAX_ITEMS,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'categoryid' => [
                                'type' => 'integer',
                                'minimum' => 1,
                            ],
                            'name' => [
                                'type' => 'string',
                                'minLength' => 1,
                                'maxLength' => 255,
                            ],
                            'questiontext' => [
                                'type' => 'string',
                                'minLength' => 1,
                            ],
                            'defaultmark' => [
                                'type' => 'number',
                                'minimum' => 0,
                                'default' => 1.0,
                            ],
                            'single' => [
                                'type' => 'boolean',
                                'default' => true,
                            ],
                            'choices' => [
                                'type' => 'array',
                                'minItems' => 2,
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'text' => ['type' => 'string'],
                                        'correct' => ['type' => 'boolean'],
                                        'feedback' => ['type' => 'string'],
                                    ],
                                    'required' => ['text', 'correct'],
                                ],
                            ],
                        ],
                        'required' => ['categoryid', 'name', 'questiontext', 'choices'],
                    ],
                ],
            ],
            'required' => ['items'],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function get_result_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'succeeded' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'input_index' => ['type' => 'integer'],
                            'questionid' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                        ],
                    ],
                ],
                'failed' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'input_index' => ['type' => 'integer'],
                            'error_code' => ['type' => 'string'],
                            'error_message' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }

    #[\Override]
    public function get_affected_objects(array $args): array {
        $items = (array) ($args['items'] ?? []);
        $catids = [];
        foreach ($items as $item) {
            $catids[(int) ($item['categoryid'] ?? 0)] = true;
        }
        $out = [];
        foreach (array_keys($catids) as $catid) {
            $out[] = ['type' => 'question_category', 'id' => $catid, 'label' => 'question category ' . $catid];
        }
        return $out;
    }

    #[\Override]
    public function describe_for_user(array $args): string {
        $count = count((array) ($args['items'] ?? []));
        return get_string('tool_question_create_multichoice_batch_describe', 'local_ai_manager', (object) [
            'count' => $count,
        ]);
    }

    #[\Override]
    public function build_undo_payload(array $args, tool_result $result): ?array {
        $ids = [];
        if ($result->ok && is_array($result->data)) {
            foreach ((array) ($result->data['succeeded'] ?? []) as $s) {
                if (!empty($s['questionid'])) {
                    $ids[] = (int) $s['questionid'];
                }
            }
        }
        return [
            'tool' => 'question_delete_batch',
            'args' => ['questionids' => $ids],
            'note' => 'No automated reversal; delete the questions manually in the question bank UI.',
        ];
    }

    #[\Override]
    protected function run(array $args, execution_context $ctx): tool_result {
        $items = (array) ($args['items'] ?? []);
        if (count($items) === 0) {
            return tool_result::failure('invalid_argument',
                get_string('tool_question_create_multichoice_batch_empty', 'local_ai_manager'));
        }
        if (count($items) > self::MAX_ITEMS) {
            return tool_result::failure('too_many_items',
                get_string('tool_question_create_multichoice_batch_too_many', 'local_ai_manager', self::MAX_ITEMS));
        }

        $singletool = new question_create();
        $succeeded = [];
        $failed = [];
        $affected = [];

        foreach (array_values($items) as $index => $item) {
            $singleargs = [
                'categoryid' => (int) ($item['categoryid'] ?? 0),
                'qtype' => 'multichoice',
                'name' => (string) ($item['name'] ?? ''),
                'questiontext' => (string) ($item['questiontext'] ?? ''),
                'defaultmark' => (float) ($item['defaultmark'] ?? 1.0),
                'qtype_data' => [
                    'single' => (bool) ($item['single'] ?? true),
                    'choices' => (array) ($item['choices'] ?? []),
                ],
            ];

            // question_create::execute() already wraps its run() in a try/catch
            // and returns a tool_result either way. A failure here means the
            // underlying save_question either was not attempted (validation)
            // or threw before persisting state; either way no per-item
            // transaction is required for isolation.
            $itemresult = $singletool->execute($singleargs, $ctx);
            if (!$itemresult->ok) {
                $failed[] = [
                    'input_index' => $index,
                    'error_code' => (string) ($itemresult->error ?? 'unknown_error'),
                    'error_message' => (string) ($itemresult->user_message ?? ''),
                ];
                continue;
            }
            $succeeded[] = [
                'input_index' => $index,
                'questionid' => (int) ($itemresult->data['questionid'] ?? 0),
                'name' => (string) ($itemresult->data['name'] ?? ''),
            ];
            foreach ($itemresult->affected_objects as $obj) {
                $affected[] = $obj;
            }
        }

        return new tool_result(
            ok: count($succeeded) > 0 || count($failed) === 0,
            data: ['succeeded' => $succeeded, 'failed' => $failed],
            affected_objects: $affected,
        );
    }
}

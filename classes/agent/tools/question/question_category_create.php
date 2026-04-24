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
 * Tool: question_category_create (MBS-10761).
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
 * Create a new question category inside an existing qbank module.
 *
 * Write-tool, requires approval, reversible by deleting the created row.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_category_create extends base_tool {

    #[\Override]
    public function get_name(): string {
        return 'question_category_create';
    }

    #[\Override]
    public function get_summary(): string {
        return get_string('tool_question_category_create_summary', 'local_ai_manager');
    }

    #[\Override]
    public function get_description(): string {
        return <<<EOT
Use this tool to create a new question category inside an existing qbank module.
Typical triggers: "Lege eine neue Fragenkategorie 'Kapitel 1' an", "Create a
question category for chapter 1".

Do NOT use this tool to create a qbank module (use module_create with
modname="qbank"). If the user has not told you which qbank to put the category
in, first call question_category_list and confirm the qbank_cmid.

Behavior: Requires {qbank_cmid, name}. Optional: info (HTML description),
parent (id of another category in the same qbank; defaults to the qbank's top
category). Requires explicit approval.

Examples:
  - "Neue Fragenkategorie 'Kapitel 1' in qbank cmid 77"
    -> question_category_create({qbank_cmid:77, name:"Kapitel 1"})
EOT;
    }

    #[\Override]
    public function get_category(): string {
        return 'question';
    }

    #[\Override]
    public function get_keywords(): array {
        return ['question', 'category', 'create', 'fragenkategorie', 'anlegen'];
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
                'qbank_cmid' => [
                    'type' => 'integer',
                    'description' => 'Course module id of the target qbank module.',
                    'minimum' => 1,
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Category display name (1-255 chars).',
                    'minLength' => 1,
                    'maxLength' => 255,
                ],
                'info' => [
                    'type' => 'string',
                    'description' => 'Optional HTML description of the category.',
                    'maxLength' => 65535,
                ],
                'parent' => [
                    'type' => 'integer',
                    'description' => 'Optional parent category id (must belong to the same qbank). '
                        . 'Defaults to the qbank top category.',
                    'minimum' => 1,
                ],
            ],
            'required' => ['qbank_cmid', 'name'],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function get_result_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'categoryid' => ['type' => 'integer'],
                'contextid' => ['type' => 'integer'],
                'parent' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
            ],
        ];
    }

    #[\Override]
    public function get_affected_objects(array $args): array {
        return [[
            'type' => 'qbank_module',
            'id' => (int) ($args['qbank_cmid'] ?? 0),
            'label' => 'qbank cm ' . (int) ($args['qbank_cmid'] ?? 0),
        ]];
    }

    #[\Override]
    public function describe_for_user(array $args): string {
        return get_string('tool_question_category_create_describe', 'local_ai_manager', (object) [
            'name' => (string) ($args['name'] ?? ''),
            'qbank_cmid' => (int) ($args['qbank_cmid'] ?? 0),
        ]);
    }

    #[\Override]
    public function build_undo_payload(array $args, tool_result $result): ?array {
        if (!$result->ok || !is_array($result->data) || empty($result->data['categoryid'])) {
            return null;
        }
        return [
            'tool' => 'question_category_delete',
            'args' => ['categoryid' => (int) $result->data['categoryid']],
            'note' => 'No automated reversal; an admin must delete the category manually.',
        ];
    }

    #[\Override]
    protected function run(array $args, execution_context $ctx): tool_result {
        global $CFG, $DB;
        require_once($CFG->libdir . '/questionlib.php');

        $cmid = (int) $args['qbank_cmid'];
        $name = trim((string) $args['name']);
        $info = (string) ($args['info'] ?? '');
        $parentid = isset($args['parent']) ? (int) $args['parent'] : 0;

        $cm = get_coursemodule_from_id('qbank', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return tool_result::failure('qbank_not_found',
                get_string('tool_question_qbank_not_found', 'local_ai_manager'));
        }
        $qbankctx = \core\context\module::instance($cm->id);
        require_capability('moodle/question:managecategory', $qbankctx, $ctx->user);

        if ($parentid === 0) {
            $top = question_get_top_category($qbankctx->id, true);
            $parentid = (int) $top->id;
        } else {
            $parent = $DB->get_record('question_categories', ['id' => $parentid]);
            if (!$parent || (int) $parent->contextid !== (int) $qbankctx->id) {
                return tool_result::failure('invalid_parent',
                    get_string('tool_question_category_create_invalid_parent', 'local_ai_manager'));
            }
        }

        $manager = new \core_question\category_manager();
        $sortorder = $manager->get_max_sortorder($parentid) + 1;

        $record = (object) [
            'name' => $name,
            'info' => clean_text($info, FORMAT_HTML),
            'infoformat' => FORMAT_HTML,
            'contextid' => (int) $qbankctx->id,
            'parent' => $parentid,
            'sortorder' => $sortorder,
            'stamp' => make_unique_id_code(),
            'idnumber' => null,
        ];
        $record->id = $DB->insert_record('question_categories', $record);

        return tool_result::success(
            data: [
                'categoryid' => (int) $record->id,
                'contextid' => (int) $qbankctx->id,
                'parent' => (int) $parentid,
                'name' => format_string($name, true, ['context' => $qbankctx]),
            ],
            affected_objects: [[
                'type' => 'question_category',
                'id' => (int) $record->id,
                'label' => $name,
            ]],
        );
    }
}

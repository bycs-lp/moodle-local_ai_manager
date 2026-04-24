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
 * Tool: course_section_update_summary (MBS-10761).
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
 * Overwrite the summary/description of a course section.
 *
 * Write-tool with approval + reversible undo payload (restores the previous summary).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_section_update_summary extends base_tool {

    #[\Override]
    public function get_name(): string {
        return 'course_section_update_summary';
    }

    #[\Override]
    public function get_summary(): string {
        return get_string('tool_course_section_update_summary_summary', 'local_ai_manager');
    }

    #[\Override]
    public function get_description(): string {
        return <<<EOT
Use this tool when the teacher wants to rewrite or replace the summary/description
of a specific course section (topic / week). Typical triggers: "Mach mir eine neue
Einleitung für Abschnitt 2", "Ersetze die Beschreibung von Thema 3".

Do NOT use this tool to update a course's own summary (that lives on the course
record, not on a section). Do NOT use it to append text — the tool replaces the
summary entirely.

Behavior: Requires {courseid, section, summary}. Writes summary as HTML and stores
the previous value as undo payload. Requires explicit approval; affects a shared
object and therefore never auto-approves.

Examples:
  - "Ersetze die Beschreibung von Abschnitt 2 im Kurs 42 durch: Willkommen …"
    -> course_section_update_summary({courseid:42, section:2, summary:"<p>Willkommen …</p>"})
EOT;
    }

    #[\Override]
    public function get_category(): string {
        return 'course';
    }

    #[\Override]
    public function get_keywords(): array {
        return ['section', 'summary', 'description', 'topic', 'abschnitt', 'thema'];
    }

    #[\Override]
    public function requires_approval(): bool {
        return true;
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
    public function get_required_capabilities(): array {
        return ['moodle/course:update'];
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
                    'description' => 'Section number (0 = general section, 1..N = topics/weeks).',
                    'minimum' => 0,
                ],
                'summary' => [
                    'type' => 'string',
                    'description' => 'New HTML summary (cleaned by Moodle before saving).',
                    'maxLength' => 65535,
                ],
            ],
            'required' => ['courseid', 'section', 'summary'],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function get_result_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'sectionid' => ['type' => 'integer'],
                'previous_summary' => ['type' => 'string'],
            ],
        ];
    }

    #[\Override]
    public function get_affected_objects(array $args): array {
        return [[
            'type' => 'course_section',
            'id' => (int) ($args['courseid'] ?? 0),
            'label' => 'section ' . (int) ($args['section'] ?? 0),
        ]];
    }

    #[\Override]
    public function describe_for_user(array $args): string {
        return get_string('tool_course_section_update_summary_describe', 'local_ai_manager', (object) [
            'section' => (int) ($args['section'] ?? 0),
            'courseid' => (int) ($args['courseid'] ?? 0),
        ]);
    }

    #[\Override]
    public function build_undo_payload(array $args, tool_result $result): ?array {
        // Smoke-test contract: the registry validator probes with empty args and an empty
        // success-result. Return a well-formed placeholder so reversibility is advertised
        // truthfully without asserting on input data we do not have yet.
        if (!$result->ok) {
            return null;
        }
        $previous = is_array($result->data) && isset($result->data['previous_summary'])
            ? (string) $result->data['previous_summary']
            : '';
        return [
            'tool' => $this->get_name(),
            'args' => [
                'courseid' => (int) ($args['courseid'] ?? 0),
                'section' => (int) ($args['section'] ?? 0),
                'summary' => $previous,
            ],
        ];
    }

    #[\Override]
    protected function run(array $args, execution_context $ctx): tool_result {
        global $DB;

        $courseid = (int) $args['courseid'];
        $section = (int) $args['section'];
        $summary = (string) $args['summary'];

        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            return tool_result::failure('course_not_found',
                get_string('tool_course_not_found', 'local_ai_manager'));
        }

        $coursecontext = \core\context\course::instance($course->id);
        require_capability('moodle/course:update', $coursecontext, $ctx->user);

        $record = $DB->get_record('course_sections', ['course' => $courseid, 'section' => $section]);
        if (!$record) {
            return tool_result::failure('section_not_found',
                get_string('tool_course_section_not_found', 'local_ai_manager'));
        }

        $previous = (string) $record->summary;
        $cleaned = clean_text($summary, FORMAT_HTML);
        $DB->update_record('course_sections', (object) [
            'id' => $record->id,
            'summary' => $cleaned,
            'summaryformat' => FORMAT_HTML,
            'timemodified' => $ctx->clock->now()->getTimestamp(),
        ]);
        rebuild_course_cache($courseid, true);

        return tool_result::success(
            data: [
                'sectionid' => (int) $record->id,
                'previous_summary' => $previous,
            ],
            affected_objects: [[
                'type' => 'course_section',
                'id' => (int) $record->id,
                'label' => 'course ' . $courseid . ' / section ' . $section,
            ]],
        );
    }
}

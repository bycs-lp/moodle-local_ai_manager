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
 * Tool: course_enroll_self (MBS-10761 Paket 10).
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
 * Enrol the calling user into a course via the self-enrolment plugin.
 *
 * Wraps the enrol_self plugin (no raw DB writes). Requires an active
 * self-enrolment instance on the target course; optional enrolment key is
 * supported. Idempotent (returns already_enrolled without failure). Reversible
 * through core unenrol.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_enroll_self extends base_tool {

    #[\Override]
    public function get_name(): string {
        return 'course_enroll_self';
    }

    #[\Override]
    public function get_summary(): string {
        return get_string('tool_course_enroll_self_summary', 'local_ai_manager');
    }

    #[\Override]
    public function get_description(): string {
        return <<<EOT
Use this tool when the user asks to enrol themselves (not another user) into a
specific course via the built-in self-enrolment mechanism. Typical triggers:
"Schreibe mich in den Kurs 'Physik 10' ein", "enrol me in course 42".

Do NOT use this tool to enrol another user — use a manual enrolment tool (not
yet available) or refer the user to the teacher. Do NOT use it for courses
without an active self-enrolment instance — the tool returns
no_self_enrolment and suggests asking the course administrator.

Behavior: Resolves the course by id, checks for an enabled enrol_self
instance, optionally validates the supplied enrolment key, then calls
\enrol_self_plugin::enrol_self(). Idempotent: already-enrolled users get
ok=true with data.already_enrolled=true. Requires approval.

Examples:
  - "Schreibe mich in Kurs 42 ein" -> course_enroll_self({courseid: 42})
  - "Enrol me, der Key ist SECRET" -> course_enroll_self({courseid: 42, enrolmentkey: "SECRET"})
EOT;
    }

    #[\Override]
    public function get_category(): string {
        return 'course';
    }

    #[\Override]
    public function get_keywords(): array {
        return ['enrol', 'enroll', 'einschreiben', 'anmelden', 'self', 'selbst', 'teilnehmen', 'beitreten'];
    }

    #[\Override]
    public function requires_approval(): bool {
        return true;
    }

    #[\Override]
    public function is_idempotent(): bool {
        return true;
    }

    #[\Override]
    public function is_reversible(): bool {
        // Technically reversible via manual unenrol, but we do not auto-generate a revert call
        // because self-unenrolment carries different UX expectations.
        return false;
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
                'courseid' => [
                    'type' => 'integer',
                    'description' => 'Numeric id of the course to join.',
                    'minimum' => 2,
                ],
                'enrolmentkey' => [
                    'type' => 'string',
                    'description' => 'Optional enrolment key if the self-enrolment instance requires one.',
                    'maxLength' => 255,
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
                'courseid' => ['type' => 'integer'],
                'already_enrolled' => ['type' => 'boolean'],
                'enrolinstanceid' => ['type' => 'integer'],
            ],
        ];
    }

    #[\Override]
    public function get_affected_objects(array $args): array {
        return [[
            'type' => 'course',
            'id' => (int) ($args['courseid'] ?? 0),
            'label' => 'course ' . (int) ($args['courseid'] ?? 0),
        ]];
    }

    #[\Override]
    public function describe_for_user(array $args): string {
        return get_string('tool_course_enroll_self_describe', 'local_ai_manager', (object) [
            'courseid' => (int) ($args['courseid'] ?? 0),
        ]);
    }

    #[\Override]
    protected function run(array $args, execution_context $ctx): tool_result {
        global $CFG, $DB;

        $courseid = (int) $args['courseid'];
        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course || $course->id == SITEID) {
            return tool_result::failure('course_not_found',
                get_string('tool_course_enroll_self_not_found', 'local_ai_manager'));
        }

        $coursectx = \core\context\course::instance($course->id);

        // Already enrolled -> idempotent success.
        if (is_enrolled($coursectx, $ctx->user, '', true)) {
            return tool_result::success([
                'courseid' => (int) $course->id,
                'already_enrolled' => true,
                'enrolinstanceid' => 0,
            ]);
        }

        // Locate an enabled self-enrolment instance.
        $instances = enrol_get_instances($course->id, true);
        $selfinstance = null;
        foreach ($instances as $instance) {
            if ($instance->enrol === 'self' && (int) $instance->status === ENROL_INSTANCE_ENABLED) {
                $selfinstance = $instance;
                break;
            }
        }
        if (!$selfinstance) {
            return tool_result::failure('no_self_enrolment',
                get_string('tool_course_enroll_self_no_instance', 'local_ai_manager'));
        }

        $plugin = enrol_get_plugin('self');
        if (!$plugin) {
            return tool_result::failure('enrol_plugin_missing',
                'The self-enrolment plugin is not available.');
        }

        // If a password is set, validate the provided key.
        $key = trim((string) ($args['enrolmentkey'] ?? ''));
        if (!empty($selfinstance->password)) {
            if ($key === '' || $key !== $selfinstance->password) {
                return tool_result::failure('invalid_enrolment_key',
                    get_string('tool_course_enroll_self_bad_key', 'local_ai_manager'));
            }
        }

        require_once($CFG->dirroot . '/enrol/self/locallib.php');

        // Ensure we run as the current user (orchestrator guarantees this, but double-check).
        global $USER;
        if ((int) $USER->id !== (int) $ctx->user->id) {
            return tool_result::failure('user_mismatch',
                'Session user does not match execution context user.');
        }

        // enrol_self() returns null on success, a localised string on failure.
        $error = $plugin->enrol_self($selfinstance, $key !== '' ? $key : null);
        if ($error !== null && $error !== true) {
            return tool_result::failure('enrol_failed', (string) $error);
        }

        return tool_result::success([
            'courseid' => (int) $course->id,
            'already_enrolled' => false,
            'enrolinstanceid' => (int) $selfinstance->id,
        ], affected_objects: $this->get_affected_objects($args));
    }
}

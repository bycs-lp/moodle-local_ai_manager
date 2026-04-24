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
 * Tool: reports_get_enrolled_users (MBS-10761 Paket 10).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent\tools\reports;

use local_ai_manager\agent\execution_context;
use local_ai_manager\agent\tool_result;
use local_ai_manager\agent\tools\base_tool;

/**
 * List users enrolled in a given course, optionally filtered by role shortname.
 *
 * Read-only, no approval required. Caller must hold moodle/course:viewparticipants
 * on the course context. User names are delivered through fullname() so that
 * core identity privacy settings are respected.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reports_get_enrolled_users extends base_tool {

    #[\Override]
    public function get_name(): string {
        return 'reports_get_enrolled_users';
    }

    #[\Override]
    public function get_summary(): string {
        return get_string('tool_reports_get_enrolled_users_summary', 'local_ai_manager');
    }

    #[\Override]
    public function get_description(): string {
        return <<<EOT
Use this tool when the user wants a roster of participants in a specific
course, optionally restricted to one role (students, teachers, ...). Typical
triggers: "Welche Schüler sind im Kurs 'Bio 9a'?", "Liste aller Lehrer in
Kurs 42", "how many students are enrolled in course 17?".

Do NOT use this tool to search for users globally — there is no global user
search tool. Do NOT use it to list inactive users — use
reports_inactive_users. Do NOT use it to enumerate groups of the course — use
a dedicated groups tool (not yet available).

Behavior: Requires {courseid}. Optional: roleshortname ('student', 'teacher',
'editingteacher', ...), limit (1-200, default 50). Returns
{courseid, count, users:[{id, fullname, email?, roles:[string]}]}. Email is
only included when the caller has moodle/site:viewuseridentity on the course
context. No approval required.

Examples:
  - "Alle Teilnehmer in Kurs 42" -> reports_get_enrolled_users({courseid: 42})
  - "Lehrer in Kurs 42" -> reports_get_enrolled_users({courseid: 42, roleshortname: "editingteacher"})
EOT;
    }

    #[\Override]
    public function get_category(): string {
        return 'reports';
    }

    #[\Override]
    public function get_keywords(): array {
        return ['enrolled', 'participants', 'teilnehmer', 'schüler', 'studenten', 'lehrer',
            'teacher', 'students', 'roster', 'liste', 'wer'];
    }

    #[\Override]
    public function get_required_capabilities(): array {
        // Evaluated per-run against the execution context; for discovery we advertise the baseline.
        return [];
    }

    #[\Override]
    public function get_parameters_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'courseid' => [
                    'type' => 'integer',
                    'description' => 'Numeric id of the target course.',
                    'minimum' => 2,
                ],
                'roleshortname' => [
                    'type' => 'string',
                    'description' => 'Optional role shortname filter (e.g. "student", "editingteacher").',
                    'maxLength' => 100,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of users to return (1-200, default 50).',
                    'minimum' => 1,
                    'maximum' => 200,
                    'default' => 50,
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
                'count' => ['type' => 'integer'],
                'users' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'fullname' => ['type' => 'string'],
                            'email' => ['type' => 'string'],
                            'roles' => ['type' => 'array', 'items' => ['type' => 'string']],
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
        $roleshortname = trim((string) ($args['roleshortname'] ?? ''));
        $limit = max(1, min(200, (int) ($args['limit'] ?? 50)));

        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course || $course->id == SITEID) {
            return tool_result::failure('course_not_found',
                get_string('tool_course_not_found', 'local_ai_manager'));
        }
        $coursectx = \core\context\course::instance($course->id);

        if (!has_capability('moodle/course:viewparticipants', $coursectx, $ctx->user)) {
            return tool_result::failure('missing_capability',
                get_string('tool_missing_capability', 'local_ai_manager',
                    'moodle/course:viewparticipants'));
        }
        $canseeemail = has_capability('moodle/site:viewuseridentity', $coursectx, $ctx->user);

        // Resolve role filter, if any.
        $roleid = 0;
        if ($roleshortname !== '') {
            $role = $DB->get_record('role', ['shortname' => $roleshortname]);
            if (!$role) {
                return tool_result::failure('role_not_found',
                    get_string('tool_reports_role_not_found', 'local_ai_manager', s($roleshortname)));
            }
            $roleid = (int) $role->id;
        }

        // Enrolled users, limited, ordered by lastname/firstname.
        $users = get_enrolled_users(
            $coursectx,
            '',
            0,
            'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, '
                . 'u.middlename, u.alternatename, u.email',
            'u.lastname ASC, u.firstname ASC',
            0,
            $limit,
            true,
            $roleid
        );

        // Gather role shortnames per user (single query).
        $userids = array_map(fn($u) => (int) $u->id, $users);
        $rolesbyuser = [];
        if (!empty($userids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
            $sql = "SELECT ra.userid, r.shortname
                      FROM {role_assignments} ra
                      JOIN {role} r ON r.id = ra.roleid
                     WHERE ra.contextid = :ctxid AND ra.userid {$insql}";
            $inparams['ctxid'] = $coursectx->id;
            $rows = $DB->get_recordset_sql($sql, $inparams);
            foreach ($rows as $row) {
                $rolesbyuser[(int) $row->userid][] = (string) $row->shortname;
            }
            $rows->close();
        }

        $out = [];
        foreach ($users as $u) {
            $entry = [
                'id' => (int) $u->id,
                'fullname' => fullname($u),
                'email' => $canseeemail ? (string) $u->email : '',
                'roles' => array_values(array_unique($rolesbyuser[(int) $u->id] ?? [])),
            ];
            $out[] = $entry;
        }

        return tool_result::success([
            'courseid' => (int) $course->id,
            'count' => count($out),
            'users' => $out,
        ]);
    }
}

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
 * local_ai_manager privacy provider class.
 *
 * @package    local_ai_manager
 * @copyright  ISB Bayern, 2024
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_ai_manager\local\data_wiper;

/**
 * local_ai_manager privacy provider class.
 *
 * @package    local_ai_manager
 * @copyright  ISB Bayern, 2024
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    #[\Override]
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'local_ai_manager_request_log',
            [
                'userid' => 'privacy:metadata:local_ai_manager_request_log:userid',
                'prompttext' => 'privacy:metadata:local_ai_manager_request_log:prompttext',
                'promptcompletion' => 'privacy:metadata:local_ai_manager_request_log:promptcompletion',
                'requestoptions' => 'privacy:metadata:local_ai_manager_request_log:requestoptions',
                'contextid' => 'privacy:metadata:local_ai_manager_request_log:contextid',
                'timecreated' => 'privacy:metadata:local_ai_manager_request_log:timecreated',
            ],
            'privacy:metadata:local_ai_manager_request_log'
        );

        $collection->add_database_table(
            'local_ai_manager_userinfo',
            [
                'userid' => 'privacy:metadata:local_ai_manager_userinfo:userid',
                'role' => 'privacy:metadata:local_ai_manager_userinfo:role',
                'locked' => 'privacy:metadata:local_ai_manager_userinfo:locked',
                'confirmed' => 'privacy:metadata:local_ai_manager_userinfo:confirmed',
                'scope' => 'privacy:metadata:local_ai_manager_userinfo:scope',
                'timemodified' => 'privacy:metadata:local_ai_manager_userinfo:timemodified',
            ],
            'privacy:metadata:local_ai_manager_userinfo'
        );

        $collection->add_database_table(
            'local_ai_manager_userusage',
            [
                'userid' => 'privacy:metadata:local_ai_manager_userusage:userid',
                'purpose' => 'privacy:metadata:local_ai_manager_userusage:purpose',
                'currentusage' => 'privacy:metadata:local_ai_manager_userusage:currentusage',
                'timemodified' => 'privacy:metadata:local_ai_manager_userusage:timemodified',
            ],
            'privacy:metadata:local_ai_manager_userusage'
        );

        $collection->add_database_table(
            'local_ai_manager_agent_runs',
            [
                'userid' => 'privacy:metadata:local_ai_manager_agent_runs:userid',
                'contextid' => 'privacy:metadata:local_ai_manager_agent_runs:contextid',
                'component' => 'privacy:metadata:local_ai_manager_agent_runs:component',
                'user_prompt' => 'privacy:metadata:local_ai_manager_agent_runs:user_prompt',
                'entity_context' => 'privacy:metadata:local_ai_manager_agent_runs:entity_context',
                'status' => 'privacy:metadata:local_ai_manager_agent_runs:status',
                'timecreated' => 'privacy:metadata:local_ai_manager_agent_runs:timecreated',
            ],
            'privacy:metadata:local_ai_manager_agent_runs'
        );

        $collection->add_database_table(
            'local_ai_manager_tool_calls',
            [
                'toolname' => 'privacy:metadata:local_ai_manager_tool_calls:toolname',
                'args_json' => 'privacy:metadata:local_ai_manager_tool_calls:args_json',
                'result_json' => 'privacy:metadata:local_ai_manager_tool_calls:result_json',
                'approval_state' => 'privacy:metadata:local_ai_manager_tool_calls:approval_state',
                'approved_by' => 'privacy:metadata:local_ai_manager_tool_calls:approved_by',
                'timecreated' => 'privacy:metadata:local_ai_manager_tool_calls:timecreated',
            ],
            'privacy:metadata:local_ai_manager_tool_calls'
        );

        $collection->add_database_table(
            'local_ai_manager_trust_prefs',
            [
                'userid' => 'privacy:metadata:local_ai_manager_trust_prefs:userid',
                'toolname' => 'privacy:metadata:local_ai_manager_trust_prefs:toolname',
                'scope' => 'privacy:metadata:local_ai_manager_trust_prefs:scope',
                'expires' => 'privacy:metadata:local_ai_manager_trust_prefs:expires',
                'timecreated' => 'privacy:metadata:local_ai_manager_trust_prefs:timecreated',
            ],
            'privacy:metadata:local_ai_manager_trust_prefs'
        );

        return $collection;
    }

    #[\Override]
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // Now determine the context ids for the request logs.
        $sql = "SELECT DISTINCT contextid FROM {local_ai_manager_request_log} WHERE userid = :userid";
        $contextlist->add_from_sql($sql, ['userid' => $userid]);

        // Agent runs also live inside concrete contexts.
        $agentsql = "SELECT DISTINCT contextid FROM {local_ai_manager_agent_runs} WHERE userid = :userid";
        $contextlist->add_from_sql($agentsql, ['userid' => $userid]);

        if (!in_array(SYSCONTEXTID, $contextlist->get_contextids())) {
            // Records in local_ai_manager_userinfo, local_ai_manager_userusage and local_ai_manager_trust_prefs are considered
            // to live in the system context.
            $contextlist->add_system_context();
        }

        return $contextlist;
    }

    #[\Override]
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        if ($contextlist->count() === 0) {
            return;
        }

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->id === SYSCONTEXTID) {
                $userinforecord = $DB->get_record('local_ai_manager_userinfo', ['userid' => $userid]);
                writer::with_context($context)->export_data(
                    [
                        get_string('pluginname', 'local_ai_manager'),
                        get_string('privacy:metadata:local_ai_manager_userinfo', 'local_ai_manager'),
                    ],
                    (object) ['userinfo' => $userinforecord]
                );
                $userusagerecords = $DB->get_records('local_ai_manager_userusage', ['userid' => $userid]);
                $userusageobjects = [];
                foreach ($userusagerecords as $userusage) {
                    $purpose = $userusage->purpose;
                    unset($userusage->purpose);
                    $userusageobjects[$purpose] = $userusage;
                }
                writer::with_context($context)->export_data(
                    [
                        get_string('pluginname', 'local_ai_manager'),
                        get_string('privacy:metadata:local_ai_manager_userusage', 'local_ai_manager'),
                    ],
                    (object) ['userusage' => $userusageobjects]
                );
                $trustrecords = $DB->get_records('local_ai_manager_trust_prefs', ['userid' => $userid]);
                if (!empty($trustrecords)) {
                    writer::with_context($context)->export_data(
                        [
                            get_string('pluginname', 'local_ai_manager'),
                            get_string('privacy:metadata:local_ai_manager_trust_prefs', 'local_ai_manager'),
                        ],
                        (object) ['trustprefs' => array_values($trustrecords)]
                    );
                }
            }
            $entries = $DB->get_records('local_ai_manager_request_log', ['userid' => $userid, 'contextid' => $context->id]);
            if (!empty($entries)) {
                writer::with_context($context)->export_data(
                // We add two structure levels here: Inside a given context (for example a specific chat block instance) we
                // define a category "AI Manager" and a subcategory "Request Logs".
                // For "reasons" these categories are referred to "subcontexts" by moodle which is an irritating naming.
                    [
                        get_string('pluginname', 'local_ai_manager'),
                        get_string('privacy:metadata:local_ai_manager_request_log', 'local_ai_manager'),
                    ],
                    (object) ['requests' => $entries]
                );
            }
            // Agent runs + associated tool-call trace for this context.
            $runs = $DB->get_records('local_ai_manager_agent_runs', ['userid' => $userid, 'contextid' => $context->id]);
            if (!empty($runs)) {
                [$insql, $inparams] = $DB->get_in_or_equal(array_keys($runs), SQL_PARAMS_NAMED, 'rid');
                $toolcalls = $DB->get_records_select('local_ai_manager_tool_calls', "runid $insql", $inparams);
                foreach ($runs as $run) {
                    $run->toolcalls = array_values(array_filter(
                        $toolcalls,
                        static fn($tc) => (int) $tc->runid === (int) $run->id,
                    ));
                }
                writer::with_context($context)->export_data(
                    [
                        get_string('pluginname', 'local_ai_manager'),
                        get_string('privacy:metadata:local_ai_manager_agent_runs', 'local_ai_manager'),
                    ],
                    (object) ['agentruns' => array_values($runs)]
                );
            }
        }
    }

    #[\Override]
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        if ($contextlist->count() === 0) {
            return;
        }
        $datawiper = new data_wiper();
        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_SYSTEM) {
                $datawiper->delete_userinfo($userid);
                $datawiper->delete_userusage($userid);
            }
            $recordsincontext = $DB->get_records(
                'local_ai_manager_request_log',
                ['userid' => $userid, 'contextid' => $context->id]
            );
            foreach ($recordsincontext as $record) {
                $anonymizecontext = false;
                $context = \context::instance_by_id($record->contextid, IGNORE_MISSING);
                if ($context && $context->contextlevel === CONTEXT_USER) {
                    $anonymizecontext = true;
                }
                // We only anonymize request logs, but do not delete them. This process removes all user associated data from the
                // request log.
                // We cannot delete the data completely, because also log data and statistics we aggregate from the logs would be
                // lost.
                $datawiper->anonymize_request_log_record($record, $anonymizecontext);
            }
        }
        // Trust preferences are user-chosen settings without aggregate value — delete outright.
        $datawiper->delete_trust_prefs_for_user($userid);
        // Anonymize agent runs + tool-call traces (keep rows for aggregate statistics).
        $datawiper->anonymize_agent_data_for_user($userid);
    }

    #[\Override]
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        // We are putting everything into a single SQL with union to avoid having duplicate user ids in the $userlist.
        $sql = "SELECT DISTINCT userid FROM {local_ai_manager_request_log} WHERE contextid = :contextid"
            . " UNION SELECT DISTINCT userid FROM {local_ai_manager_agent_runs} WHERE contextid = :contextid2";
        $params = ['contextid' => $context->id, 'contextid2' => $context->id];

        if ($context->id === SYSCONTEXTID) {
            $sql .= " UNION SELECT DISTINCT userid FROM {local_ai_manager_userinfo}"
                . " UNION SELECT DISTINCT userid FROM {local_ai_manager_userusage}"
                . " UNION SELECT DISTINCT userid FROM {local_ai_manager_trust_prefs}";
        }

        $userlist->add_from_sql('userid', $sql, $params);
    }

    #[\Override]
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();

        if ($userlist->count() === 0) {
            return;
        }

        $datawiper = new data_wiper();

        if ($context->id === SYSCONTEXTID) {
            foreach ($userlist->get_userids() as $userid) {
                $datawiper->delete_userinfo($userid);
                $datawiper->delete_userusage($userid);
                $datawiper->delete_trust_prefs_for_user($userid);
            }
        }
        [$insql, $inparams] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        $params = array_merge($inparams, ['contextid' => $context->id]);
        $requestlogsrecords = $DB->get_records_select('local_ai_manager_request_log', "userid $insql", $params);

        $anonymizecontext = $context->contextlevel === CONTEXT_USER;
        foreach ($requestlogsrecords as $record) {
            $datawiper->anonymize_request_log_record($record, $anonymizecontext);
        }
        foreach ($userlist->get_userids() as $userid) {
            $datawiper->anonymize_agent_data_for_user($userid);
        }
    }

    #[\Override]
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context instanceof \context_system) {
            $DB->delete_records('local_ai_manager_userinfo');
            $DB->delete_records('local_ai_manager_userusage');
            $DB->delete_records('local_ai_manager_trust_prefs');
        }

        $datawiper = new data_wiper();
        $requestlogrecords = $DB->get_records('local_ai_manager_request_log', ['contextid' => $context->id]);

        foreach ($requestlogrecords as $record) {
            $datawiper->anonymize_request_log_record($record, $context->contextlevel === CONTEXT_USER);
        }

        // Anonymize agent runs inside this context (keep rows for aggregate statistics).
        $runs = $DB->get_records('local_ai_manager_agent_runs', ['contextid' => $context->id]);
        foreach ($runs as $run) {
            $run->userid = 0;
            $run->user_prompt = data_wiper::ANONYMIZE_STRING;
            $run->entity_context = null;
            $run->error_message = null;
            $DB->update_record('local_ai_manager_agent_runs', $run);
            $DB->execute(
                'UPDATE {local_ai_manager_tool_calls}
                    SET args_json = :args, result_json = NULL, approved_by = NULL,
                        error_message = NULL, undo_payload = NULL
                  WHERE runid = :runid',
                ['args' => data_wiper::ANONYMIZE_STRING, 'runid' => $run->id],
            );
        }
    }
}

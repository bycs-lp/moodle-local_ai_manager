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
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\metadata\provider {
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

        return $collection;
    }

    #[\Override]
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // Now determine the context ids for the request logs.
        $sql = "SELECT DISTINCT contextid FROM {local_ai_manager_request_log} WHERE userid = :userid";
        $contextlist->add_from_sql($sql, ['userid' => $userid]);

        if (!in_array(SYSCONTEXTID, $contextlist->get_contextids())) {
            // Records in local_ai_manager_userinfo and local_ai_manager_userusage are considered to live in the system context.
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
        }
    }

    #[\Override]
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        if ($contextlist->count() === 0) {
            return;
        }
        $datawiper = new data_wiper();

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_SYSTEM) {
                $datawiper->delete_userinfo($contextlist->get_user()->id);
                $datawiper->delete_userusage($contextlist->get_user()->id);
            }
            $recordsincontext = $DB->get_records(
                'local_ai_manager_request_log',
                ['userid' => $contextlist->get_user()->id, 'contextid' => $context->id]
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
    }

    #[\Override]
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        // We are putting everything into a single SQL with union to avoid having duplicate user ids in the $userlist.
        $sql = "SELECT DISTINCT userid FROM {local_ai_manager_request_log} WHERE contextid = :contextid";

        if ($context->id === SYSCONTEXTID) {
            $sql .= " UNION SELECT DISTINCT userid FROM {local_ai_manager_userinfo}"
                . " UNION SELECT DISTINCT userid FROM {local_ai_manager_userusage}";
        }

        $userlist->add_from_sql('userid', $sql, ['contextid' => $context->id]);
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
            }
        }
        [$insql, $inparams] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        $params = array_merge($inparams, ['contextid' => $context->id]);
        $requestlogsrecords = $DB->get_records_select('local_ai_manager_request_log', "userid $insql", $params);

        $anonymizecontext = $context->contextlevel === CONTEXT_USER;
        foreach ($requestlogsrecords as $record) {
            $datawiper->anonymize_request_log_record($record, $anonymizecontext);
        }
    }

    #[\Override]
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context instanceof \context_system) {
            $DB->delete_records('local_ai_manager_userinfo');
            $DB->delete_records('local_ai_manager_userusage');
        }

        $datawiper = new data_wiper();
        $requestlogrecords = $DB->get_records('local_ai_manager_request_log', ['contextid' => $context->id]);

        foreach ($requestlogrecords as $record) {
            $datawiper->anonymize_request_log_record($record, $context->contextlevel === CONTEXT_USER);
        }
    }
}

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

namespace local_ai_manager\local;

use core\clock;
use local_ai_manager\base_instance;
use local_ai_manager\hook\custom_tenant;
use stdClass;

/**
 * Class for handling anonymizing user data.
 *
 * @package    local_ai_manager
 * @copyright  2025 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class data_wiper {
    /** @var int the date until which all data should be anonymized. */
    private int $anonymizedate;

    /** @var int the date until which all data should be deleted. */
    private int $deletedate;

    /** @var string String that is being used for overwriting prompts and prompt completions to anonymize data. */
    const ANONYMIZE_STRING = 'ANONYMIZED';

    /**
     * Creates the anonymizer object.
     */
    public function __construct() {
        $this->anonymizedate = intval(get_config('local_ai_manager', 'datawiperanonymizedate'));
        $this->deletedate = intval(get_config('local_ai_manager', 'datawiperdeletedate'));
    }

    /**
     * Deletes all entries from the local_ai_manager_request_log table that are older than the corresponding admin setting.
     */
    public function cleanup_request_log_data(): void {
        // We delete first, because we do not need to anonymize things we will delete anyway.
        $this->delete_request_log_data();
        $this->anonymize_request_log_data();
    }

    /**
     * Anonymizes all entries from the local_ai_manager_request_log table that are older than the corresponding admin setting.
     */
    public function anonymize_request_log_data(): void {
        global $DB;
        $rs = $DB->get_recordset_select(
            'local_ai_manager_request_log',
            "timecreated < :anonymizedate",
            ['anonymizedate' => $this->anonymizedate]
        );
        foreach ($rs as $record) {
            $anonymizecontext = false;
            $context = \context::instance_by_id($record->contextid, IGNORE_MISSING);
            if ($context && $context->contextlevel == CONTEXT_USER && $context->instanceid == $record->userid) {
                $anonymizecontext = true;
            }
            $this->anonymize_request_log_record($record, $anonymizecontext);
        }
        $rs->close();
    }

    /**
     * Deletes all entries from the local_ai_manager_request_log table that are older than the corresponding admin setting.
     */
    public function delete_request_log_data(): void {
        global $DB;
        $rs = $DB->get_recordset_select(
            'local_ai_manager_request_log',
            "timecreated < :deletedate",
            ['deletedate' => $this->deletedate]
        );
        foreach ($rs as $record) {
            $DB->delete_records('local_ai_manager_request_log', ['id' => $record->id]);
        }
        $rs->close();
    }

    /**
     * Anonymizes a given database record from the local_ai_manager_request_log table.
     *
     * All personal data will be removed from the record.
     *
     * @param stdClass $record the database record from local_ai_manager_request_log table
     * @param bool $anonymizecontext if context should also be anonymized, typically only use if it is a user context,
     *  defaults to false
     */
    public function anonymize_request_log_record(stdClass $record, bool $anonymizecontext = false): void {
        global $DB;
        $record->userid = null;
        $record->prompttext = self::ANONYMIZE_STRING;
        $record->promptcompletion = self::ANONYMIZE_STRING;
        $record->requestoptions = self::ANONYMIZE_STRING;
        if ($anonymizecontext) {
            $record->contextid = null;
        }
        $DB->update_record('local_ai_manager_request_log', $record);
    }

    /**
     * Anonymizes the request log records for a given user.
     *
     * @param int $userid the id of the user whose request logs should be anonymized
     * @param bool $forceanonymize by default only records older than the "datawiperanonymizedate" setting are anonymized,
     *  use $forceanonymize = true to anonymize ALL records for this user
     */
    public function anonymize_request_log_for_user(int $userid, bool $forceanonymize = false): void {
        global $DB;
        $select = "userid = :userid";
        $params = ['userid' => $userid];
        if (!$forceanonymize) {
            $select .= " AND timecreated < :anonymizedate";
            $params['anonymizedate'] = $this->anonymizedate;
        }
        $rs = $DB->get_recordset_select('local_ai_manager_request_log', $select, $params);
        foreach ($rs as $record) {
            $this->anonymize_request_log_record($record);
        }
        $rs->close();
    }

    /**
     * Delete the userinfo record of a user.
     *
     * @param int $userid the id of the user whose userinfo record should be deleted
     */
    public function delete_userinfo(int $userid): void {
        $userinfo = new userinfo($userid);
        $userinfo->delete();
    }

    /**
     * Deletes all user usage records (for all purposes) of a user.
     *
     * @param int $userid the id of the user whose userusage records should be deleted
     */
    public function delete_userusage(int $userid): void {
        global $DB;
        // We do not create objects and use a delete routine from the userusage object, because we do not want to iterate
        // over the purposes, because we want to get rid of all userusage entries for this user.
        $DB->delete_records('local_ai_manager_userusage', ['userid' => $userid]);
    }
}

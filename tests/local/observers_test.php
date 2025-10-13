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

/**
 * Unit tests for the observers class of local_ai_manager.
 *
 * @package   local_ai_manager
 * @copyright 2025 ISB Bayern
 * @author    Philipp Memmel
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_ai_manager\local\observers
 */
final class observers_test extends \advanced_testcase {
    /**
     * Tests the event handler for the {@see user_deleted} event.
     *
     * It currently somehow doubles the {@see \local_ai_manager\local\data_wiper_test} class, but we should
     * have separate observer tests to make sure everything is running properly.
     *
     * @covers \local_ai_manager\local\observers::user_deleted
     */
    public function test_user_deleted(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $userinfo = new userinfo($user->id);
        $userinfo->store();

        // Reload to verify we have a real DB record.
        $userinfo->load();
        $this->assertTrue($userinfo->record_exists());

        $connectorfactory = \core\di::get(connector_factory::class);
        $chatpurpose = $connectorfactory->get_purpose_by_purpose_string('chat');
        $chatuserusage = new userusage($chatpurpose, $user->id);
        $chatuserusage->set_currentusage(60.0);
        $chatuserusage->store();
        // Reload to verify we have a real DB record.
        $chatuserusage->load();
        $this->assertTrue($chatuserusage->record_exists());

        // We quickly fake a request log object in the database. It's not important that it's perfect,
        // things like these will be tested by the data_wiper tests. We just need to know that the anonymizer
        // routine has been called when the user has been deleted.
        $requestlog = new \stdClass();
        $requestlog->userid = $user->id;
        $requestlog->purpose = $chatpurpose->get_plugin_name();
        $requestlog->prompttext = 'Test prompt';
        $requestlog->promptcompletion = 'Test completion';
        $requestlog->timecreated = time();
        $DB->insert_record('local_ai_manager_request_log', $requestlog);

        $this->assertTrue($DB->record_exists('local_ai_manager_request_log', ['userid' => $user->id]));
        // The record should later be anonymized, we need to store the id to re-read it from the database.
        // Due to anonymization we will not be able to use the user id as query parameter.
        $recordid = $DB->get_field('local_ai_manager_request_log', 'id', ['userid' => $user->id]);

        // Now delete the user and trigger the event and thus our observer.
        delete_user($user);

        $userinfo = new userinfo($user->id);
        $this->assertFalse($userinfo->record_exists());

        $chatuserusage = new userusage($chatpurpose, $user->id);
        $this->assertFalse($chatuserusage->record_exists());

        $requestlogrecord = $DB->get_record('local_ai_manager_request_log', ['id' => $recordid]);
        // Record should still exist.
        $this->assertNotFalse($requestlogrecord);
        // But should have been anonymized.
        $this->assertNull($requestlogrecord->userid);
        $this->assertEquals($requestlogrecord->prompttext, data_wiper::ANONYMIZE_STRING);
        $this->assertEquals($requestlogrecord->promptcompletion, data_wiper::ANONYMIZE_STRING);
    }
}

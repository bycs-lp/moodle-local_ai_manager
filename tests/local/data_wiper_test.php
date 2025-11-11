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

use context_system;
use GuzzleHttp\Psr7\Stream;
use local_ai_manager\manager;
use local_ai_manager\request_options;
use stdClass;

/**
 * Test class for the data_wiper class.
 *
 * @package    local_ai_manager
 * @copyright  2025 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class data_wiper_test extends \advanced_testcase {
    /** @var stdClass The user object of the first test user. */
    private stdClass $user1;

    /** @var stdClass A second test user object. */
    private stdClass $user2;

    /**
     * Basic setup.
     */
    protected function setUp(): void {
        parent::setUp();
        $tenant = new tenant('1234');

        // We disable the hooks here, so we have a defined setup for these unit tests.
        // The hook callbacks should be tested wherever the callbacks are being implemented.
        $this->redirectHook(\local_ai_manager\hook\userinfo_extend::class, fn() => null);
        $this->redirectHook(\local_ai_manager\hook\custom_tenant::class, fn() => null);

        // Set the capability based on the $configuration.
        $systemcontext = context_system::instance();
        $this->user1 = $this->getDataGenerator()->create_user(['institution' => $tenant->get_sql_identifier()]);
        $this->user2 = $this->getDataGenerator()->create_user(['institution' => $tenant->get_sql_identifier()]);
        $aiuserroleid = $this->getDataGenerator()->create_role(['shortname' => 'aiuser']);
        role_assign($aiuserroleid, $this->user1->id, $systemcontext->id);
        role_assign($aiuserroleid, $this->user2->id, $systemcontext->id);
        assign_capability('local/ai_manager:use', CAP_ALLOW, $aiuserroleid, $systemcontext->id);
        $this->setUser($this->user1);

        // Set if the tenant is enabled based on the $configuration.
        // CARE: If the tenant is not allowed this will not have any effect.
        $configmanager = new config_manager($tenant);
        $configmanager->set_config('tenantenabled', 1);

        // Set locked and confirmed value based on the $configuration.
        $userinfo = new userinfo($this->user1->id);
        $userinfo->set_locked(false);
        $userinfo->set_confirmed(true);
        $userinfo->set_scope(userinfo::SCOPE_EVERYWHERE);
        $userinfo->store();
        $userinfo = new userinfo($this->user2->id);
        $userinfo->set_locked(false);
        $userinfo->set_confirmed(true);
        $userinfo->set_scope(userinfo::SCOPE_EVERYWHERE);
        $userinfo->store();
    }

    /**
     * Test the method to anonymize a single request log record.
     *
     * @covers \local_ai_manager\local\data_wiper::anonymize_request_log_record
     */
    public function test_anonymize_request_log_record(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->getDataGenerator()->enrol_user($this->user1->id, $course->id);

        $this->generate_request_log_entry('testprompt block', 'singleprompt', \context_module::instance($page->cmid));
        $this->assertEquals($this->get_latest_request_log_entry()->prompttext, 'testprompt block');
        $datawiper = new data_wiper();
        $datawiper->anonymize_request_log_record($this->get_latest_request_log_entry());
        $anonymizedrecord = $this->get_latest_request_log_entry();
        $this->assertEquals($anonymizedrecord->prompttext, data_wiper::ANONYMIZE_STRING);
        $this->assertEquals($anonymizedrecord->promptcompletion, data_wiper::ANONYMIZE_STRING);
        $this->assertEquals($anonymizedrecord->requestoptions, data_wiper::ANONYMIZE_STRING);
    }

    /**
     * Tests the anonymizing and deletion of request log data.
     *
     * @covers \local_ai_manager\local\data_wiper::anonymize_request_log_data
     * @covers \local_ai_manager\local\data_wiper::delete_request_log_data
     */
    public function test_anonymize_and_delete_request_log_data(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->getDataGenerator()->enrol_user($this->user1->id, $course->id);
        $this->getDataGenerator()->enrol_user($this->user2->id, $course->id);
        $coursecontext = \context_course::instance($course->id);
        $chatblock = $this->getDataGenerator()->create_block('ai_chat', ['parentcontextid' => $coursecontext->id]);
        $blockcontext = \context_block::instance($chatblock->id);
        $pagecontext = \context_module::instance($page->cmid);
        $coursecontext = \context_course::instance($course->id);

        $currentime = time();
        $this->mock_clock_with_frozen($currentime);

        $anonymizedate = $currentime + DAYSECS + 5;
        $deletedate = $currentime + DAYSECS + 5;
        set_config('datawiperanonymizedate', $anonymizedate, 'local_ai_manager');
        set_config('datawiperdeletedate', $deletedate, 'local_ai_manager');
        // Needs to be instantiated after setting the anonymize date!
        $datawiper = new data_wiper();

        $recordidsbeforeanonymizedate[] = $this->generate_request_log_entry('chat user1', 'chat', $blockcontext);
        $this->assertEquals($this->get_latest_request_log_entry()->prompttext, 'chat user1');
        $this->setUser($this->user2);
        $recordidsbeforeanonymizedate[] = $this->generate_request_log_entry('chat user2', 'chat', $blockcontext);
        $this->assertEquals($this->get_latest_request_log_entry()->prompttext, 'chat user2');
        $this->mock_clock_with_frozen(time() + DAYSECS);
        $this->setUser($this->user1);
        $recordidsbeforeanonymizedate[] = $this->generate_request_log_entry('imggen user1', 'imggen', $pagecontext);
        $this->assertEquals($this->get_latest_request_log_entry()->prompttext, 'imggen user1');
        $this->setUser($this->user2);
        $recordidsbeforeanonymizedate[] = $this->generate_request_log_entry('imggen user2', 'imggen', $pagecontext);
        $this->assertEquals($this->get_latest_request_log_entry()->prompttext, 'imggen user2');
        $this->setUser($this->user1);
        $this->mock_clock_with_frozen(time() + 2 * DAYSECS);
        // Now we crossed the date for anonymizing, so from now on records should be kept as they are.
        $recordidsafteranonymizedate[] = $this->generate_request_log_entry('singleprompt user1', 'singleprompt', $pagecontext);
        $this->assertEquals($this->get_latest_request_log_entry()->prompttext, 'singleprompt user1');
        $this->setUser($this->user2);
        $recordidsafteranonymizedate[] = $this->generate_request_log_entry('singleprompt user2', 'singleprompt', $pagecontext);
        $this->assertEquals($this->get_latest_request_log_entry()->prompttext, 'singleprompt user2');
        $this->setUser($this->user1);
        $this->mock_clock_with_frozen(time() + 3 * DAYSECS);
        $recordidsafteranonymizedate[] = $this->generate_request_log_entry('tts user1', 'tts', $coursecontext);
        $this->assertEquals($this->get_latest_request_log_entry()->prompttext, 'tts user1');
        $this->setUser($this->user2);
        $recordidsafteranonymizedate[] = $this->generate_request_log_entry('tts user2', 'tts', $coursecontext);
        $this->assertEquals($this->get_latest_request_log_entry()->prompttext, 'tts user2');

        $datawiper->anonymize_request_log_data();

        foreach ($recordidsbeforeanonymizedate as $recordid) {
            $record = $DB->get_record('local_ai_manager_request_log', ['id' => $recordid]);
            $this->assert_record_anonymized($record);
        }
        foreach ($recordidsafteranonymizedate as $recordid) {
            $record = $DB->get_record('local_ai_manager_request_log', ['id' => $recordid]);
            $this->assert_record_not_anonymized($record);
        }

        $datawiper->delete_request_log_data();
        foreach ($recordidsbeforeanonymizedate as $recordid) {
            $this->assertFalse($DB->record_exists('local_ai_manager_request_log', ['id' => $recordid]));
        }
        foreach ($recordidsafteranonymizedate as $recordid) {
            $record = $DB->get_record('local_ai_manager_request_log', ['id' => $recordid]);
            $this->assert_record_not_anonymized($record);
        }
    }

    /**
     * Test the anonymization of request logs for a given user.
     *
     * @covers ::anonymize_request_log_for_user
     */
    public function test_anonymize_request_log_for_user(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->getDataGenerator()->enrol_user($this->user1->id, $course->id);
        $this->getDataGenerator()->enrol_user($this->user2->id, $course->id);
        $coursecontext = \context_course::instance($course->id);
        $chatblock = $this->getDataGenerator()->create_block('ai_chat', ['parentcontextid' => $coursecontext->id]);
        $blockcontext = \context_block::instance($chatblock->id);
        $pagecontext = \context_module::instance($page->cmid);
        $coursecontext = \context_course::instance($course->id);

        $currentime = time();
        $this->mock_clock_with_frozen($currentime);

        $anonymizedate = $currentime + DAYSECS + 5;
        set_config('datawiperanonymizedate', $anonymizedate, 'local_ai_manager');
        // Needs to be instantiated after setting the anonymize date!
        $datawiper = new data_wiper();

        $recordidsbeforeanonymizedateuser1[] = $this->generate_request_log_entry('chat user1', 'chat', $blockcontext);
        $this->assertEquals($this->get_latest_request_log_entry()->prompttext, 'chat user1');
        $this->setUser($this->user2);
        $recordidsbeforeanonymizedateuser2[] = $this->generate_request_log_entry('chat user2', 'chat', $blockcontext);
        $this->assertEquals($this->get_latest_request_log_entry()->prompttext, 'chat user2');
        $this->mock_clock_with_frozen(time() + DAYSECS);
        $this->setUser($this->user1);
        $recordidsbeforeanonymizedateuser1[] = $this->generate_request_log_entry('imggen user1', 'imggen', $pagecontext);
        $this->assertEquals($this->get_latest_request_log_entry()->prompttext, 'imggen user1');
        $this->setUser($this->user2);
        $recordidsbeforeanonymizedateuser2[] = $this->generate_request_log_entry('imggen user2', 'imggen', $pagecontext);
        $this->assertEquals($this->get_latest_request_log_entry()->prompttext, 'imggen user2');
        $this->setUser($this->user1);
        $this->mock_clock_with_frozen(time() + 2 * DAYSECS);
        // Now we crossed the date for anonymizing, so from now on records should be kept as they are.
        $recordidsafteranonymizedateuser1[] = $this->generate_request_log_entry('singleprompt user1', 'singleprompt', $pagecontext);
        $this->assertEquals($this->get_latest_request_log_entry()->prompttext, 'singleprompt user1');
        $this->setUser($this->user2);
        $recordidsafteranonymizedateuser2[] = $this->generate_request_log_entry('singleprompt user2', 'singleprompt', $pagecontext);
        $this->assertEquals($this->get_latest_request_log_entry()->prompttext, 'singleprompt user2');
        $this->setUser($this->user1);
        $this->mock_clock_with_frozen(time() + 3 * DAYSECS);
        $recordidsafteranonymizedateuser1[] = $this->generate_request_log_entry('tts user1', 'tts', $coursecontext);
        $this->assertEquals($this->get_latest_request_log_entry()->prompttext, 'tts user1');
        $this->setUser($this->user2);
        $recordidsafteranonymizedateuser2[] = $this->generate_request_log_entry('tts user2', 'tts', $coursecontext);
        $this->assertEquals($this->get_latest_request_log_entry()->prompttext, 'tts user2');

        $datawiper->anonymize_request_log_for_user($this->user1->id);

        // We only anonymized user1 request logs, so we expect the ones of user 1 which are dated before the anonymize date to
        // be anonymized.
        foreach ($recordidsbeforeanonymizedateuser1 as $recordid) {
            $record = $DB->get_record('local_ai_manager_request_log', ['id' => $recordid]);
            $this->assert_record_anonymized($record);
        }
        // Requests before the anonymize date of user2 however should have been left untouched.
        foreach ($recordidsbeforeanonymizedateuser2 as $recordid) {
            $record = $DB->get_record('local_ai_manager_request_log', ['id' => $recordid]);
            $this->assert_record_not_anonymized($record);
        }
        // Requests after the anonymize date should have been left untouched.
        foreach ($recordidsafteranonymizedateuser1 as $recordid) {
            $record = $DB->get_record('local_ai_manager_request_log', ['id' => $recordid]);
            $this->assert_record_not_anonymized($record);
        }
        // Requests after the anonymize date should have been left untouched.
        foreach ($recordidsafteranonymizedateuser2 as $recordid) {
            $record = $DB->get_record('local_ai_manager_request_log', ['id' => $recordid]);
            $this->assert_record_not_anonymized($record);
        }
    }

    /**
     * Helper function to generate a real request log entry.
     *
     * @param string $prompt The prompt of the request
     * @param string $purpose The name of the purpose plugin which has been used
     * @param \context $context the context in which the request has been made
     * @return int the id of the generated request log entry
     */
    private function generate_request_log_entry(string $prompt, string $purpose, \context $context): int {

        $configmanager = \core\di::get(\local_ai_manager\local\config_manager::class);

        switch ($purpose) {
            case 'imggen':
                $purposeobject = new \aipurpose_imggen\purpose();
                $instance = new \aitool_dalle\instance();
                $instance->set_model('dalle-3');
                $connector =
                        $this->getMockBuilder('\aitool_dalle\connector')->setConstructorArgs([$instance])->getMock();
                break;
            case 'tts':
                $purposeobject = new \aipurpose_tts\purpose();
                $instance = new \aitool_openaitts\instance();
                $instance->set_model('tts1');
                $connector =
                        $this->getMockBuilder('\aitool_openaitts\connector')->setConstructorArgs([$instance])->getMock();
                break;
            case 'singleprompt':
                $purposeobject = new \aipurpose_singleprompt\purpose();
                $instance = new \aitool_chatgpt\instance();
                $instance->set_model('gpt-4o');
                $connector =
                        $this->getMockBuilder('\aitool_chatgpt\connector')->setConstructorArgs([$instance])->getMock();
                break;
            case 'chat':
            default:
                $purposeobject = new \aipurpose_chat\purpose();
                $instance = new \aitool_chatgpt\instance();
                $instance->set_model('gpt-4o');
                $connector =
                        $this->getMockBuilder('\aitool_chatgpt\connector')->setConstructorArgs([$instance])->getMock();
                break;
        }

        // Fake a stream object, because we will mock the method that access it anyway.
        $streamresponse = new Stream(fopen('php://temp', 'r+'));
        $requestresponse = request_response::create_from_result($streamresponse);

        // Fake usage object.
        $usage = new usage(50.0, 30.0, 20.0);
        // Fake prompt_response object.
        $promptresponse = prompt_response::create_from_result('gpt-4o', $usage, 'AI result');

        $connector->expects($this->any())->method('make_request')->willReturn($requestresponse);
        $connector->expects($this->any())->method('execute_prompt_completion')->willReturn($promptresponse);
        $connectorfactory =
                $this->getMockBuilder(connector_factory::class)->setConstructorArgs([$configmanager])->getMock();
        $connectorfactory->expects($this->any())->method('get_connector_by_purpose')->willReturn($connector);
        $connectorfactory->expects($this->any())->method('get_purpose_by_purpose_string')->willReturn($purposeobject);
        \core\di::set(config_manager::class, $configmanager);
        \core\di::set(connector_factory::class, $connectorfactory);

        // We disable the hook here so we have a defined setup for this unit test.
        // The hook callbacks should be tested whereever the callback is being implemented.
        $this->redirectHook(\local_ai_manager\hook\additional_user_restriction::class, fn() => null);

        $manager = new manager($purpose);

        // Now we finally finished our setup. Call the perform_request method and check the result.
        $requestoptions = new request_options($purposeobject, $context, 'block_ai_chat');
        return $manager->log_request($prompt, $promptresponse, 0.35, $requestoptions);
    }

    /**
     * Test the deletion of the userinfo.
     *
     * @covers ::delete_userinfo
     */
    public function test_delete_userinfo(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $userinfo = new userinfo($user->id);
        $userinfo->store();

        // Reload to verify we have a real DB record.
        $userinfo->load();
        $this->assertTrue($userinfo->record_exists());

        $datawiper = new data_wiper();
        $datawiper->delete_userinfo($user->id);

        $userinfo = new userinfo($user->id);
        $this->assertFalse($userinfo->record_exists());
    }

    /**
     * Test the deletion of the userusage records.
     *
     * @covers ::delete_userusage
     */
    public function test_delete_userusage(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $connectorfactory = \core\di::get(connector_factory::class);
        $chatpurpose = $connectorfactory->get_purpose_by_purpose_string('chat');
        $chatuserusage = new userusage($chatpurpose, $user->id);
        $chatuserusage->set_currentusage(60.0);
        $chatuserusage->store();
        // Reload to verify we have a real DB record.
        $chatuserusage->load();
        $this->assertTrue($chatuserusage->record_exists());

        $singlepromptpurpose = $connectorfactory->get_purpose_by_purpose_string('singleprompt');
        $singlepromptuserusage = new userusage($singlepromptpurpose, $user->id);
        $singlepromptuserusage->set_currentusage(30.0);
        $singlepromptuserusage->store();
        // Reload to verify we have a real DB record.
        $singlepromptuserusage->load();
        $this->assertTrue($singlepromptuserusage->record_exists());

        $datawiper = new data_wiper();
        $datawiper->delete_userusage($user->id);

        $chatuserusage = new userusage($chatpurpose, $user->id);
        $singlepromptuserusage = new userusage($singlepromptpurpose, $user->id);
        $this->assertFalse($chatuserusage->record_exists());
        $this->assertFalse($singlepromptuserusage->record_exists());
    }

    /**
     * Helper function to get the latest request log record from the database.
     *
     * @return stdClass the latest request log record
     */
    private function get_latest_request_log_entry(): stdClass {
        global $DB;
        $requestlog = $DB->get_records('local_ai_manager_request_log', [], 'id DESC', '*', 0, 1);
        return reset($requestlog);
    }

    /**
     * Helper function to assert that a given request_log record has been properly anonymized.
     *
     * @param stdClass $record the request log record to check
     */
    private function assert_record_anonymized(stdClass $record): void {
        $this->assertNull($record->userid);
        $this->assertEquals(data_wiper::ANONYMIZE_STRING, $record->prompttext);
        $this->assertEquals(data_wiper::ANONYMIZE_STRING, $record->promptcompletion);
    }

    /**
     * Helper function to assert that a given request_log record has NOT been properly anonymized.
     *
     * @param stdClass $record the request log record to check
     */
    private function assert_record_not_anonymized(stdClass $record): void {
        $this->assertNotNull($record->userid);
        $this->assertNotEquals(data_wiper::ANONYMIZE_STRING, $record->prompttext);
        $this->assertNotEquals(data_wiper::ANONYMIZE_STRING, $record->promptcompletion);
    }
}

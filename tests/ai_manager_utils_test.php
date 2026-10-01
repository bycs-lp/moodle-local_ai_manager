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

namespace local_ai_manager;

use aitool_chatgpt\instance;
use local_ai_manager\hook\additional_user_restriction;
use local_ai_manager\hook\purpose_usage;
use local_ai_manager\local\access_manager;
use local_ai_manager\local\config_manager;
use local_ai_manager\local\connector_factory;
use local_ai_manager\local\tenant;
use local_ai_manager\local\userinfo;
use local_ai_manager\local\userusage;
use local_ai_manager\plugininfo\aipurpose;
use stdClass;

/**
 * Test class for the ai_manager_utils functions.
 *
 * @package    local_ai_manager
 * @copyright  2024 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ai_manager_utils_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        // We disable the hooks here, so we have a defined setup for these unit tests.
        // The hook callbacks should be tested wherever the callbacks are being implemented.
        $this->redirectHook(\local_ai_manager\hook\userinfo_extend::class, fn() => null);
        $this->redirectHook(\local_ai_manager\hook\custom_tenant::class, fn() => null);
    }

    /**
     * Tests the method get_next_free_itemid.
     *
     * @covers \local_ai_manager\ai_manager_utils::get_next_free_itemid
     */
    public function test_get_next_free_itemid(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $this->assertEquals(1, ai_manager_utils::get_next_free_itemid('block_ai_chat', 12));

        $record = new stdClass();
        $record->userid = $user->id;
        $record->value = 3.8;
        $record->model = 'testmodel';
        $record->modelinfo = 'testmodel-3.5';
        $record->prompttext = 'some prompt';
        $record->promptcompletion = 'some prompt response';
        $record->component = 'block_ai_chat';
        $record->contextid = 12;
        $record->itemid = 5;
        $record->timecreated = time();
        $DB->insert_record('local_ai_manager_request_log', $record);

        $record = new stdClass();
        $record->userid = $user->id;
        $record->value = 2.3;
        $record->model = 'anothertestmodel';
        $record->modelinfo = 'anothertestmodel-4.0';
        $record->prompttext = 'some other prompt';
        $record->promptcompletion = 'some prompt response';
        $record->component = 'block_ai_chat';
        $record->contextid = 12;
        $record->itemid = 7;
        $record->timecreated = time();
        $DB->insert_record('local_ai_manager_request_log', $record);

        $this->assertEquals(8, ai_manager_utils::get_next_free_itemid('block_ai_chat', 12));

        $record = new stdClass();
        $record->userid = $user->id;
        $record->value = 2.3;
        $record->model = 'anothertestmodel';
        $record->modelinfo = 'anothertestmodel-4.0';
        $record->prompttext = 'some other prompt';
        $record->promptcompletion = 'some prompt response';
        $record->component = 'block_ai_chat';
        // Other context id, so this record should not be relevant.
        $record->contextid = 23;
        $record->itemid = 10;
        $record->timecreated = time();
        $DB->insert_record('local_ai_manager_request_log', $record);

        $this->assertEquals(8, ai_manager_utils::get_next_free_itemid('block_ai_chat', 12));
        $this->assertEquals(1, ai_manager_utils::get_next_free_itemid('mod_ai', 23));
        $this->assertEquals(11, ai_manager_utils::get_next_free_itemid('block_ai_chat', 23));
    }

    /**
     * Tests the function to calculate the closest parent course context.
     *
     * @covers \local_ai_manager\ai_manager_utils::find_closest_parent_course_context
     */
    public function test_find_closest_parent_course_context(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $coursecat = $this->getDataGenerator()->create_category();
        $subcoursecat = $this->getDataGenerator()->create_category(['parent' => $coursecat->id]);
        $pagecoursemodule = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(
            ['course' => $course->id]
        );
        $pagecoursemodule2 = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(
            ['course' => $course2->id]
        );
        $blockusercontext = $this->getDataGenerator()->create_block(
            'html',
            ['parentcontextid' => \context_user::instance($user->id)->id]
        );
        $blocksystemcontext = $this->getDataGenerator()->create_block(
            'html',
            ['parentcontextid' => \context_system::instance()->id]
        );
        $blockcoursecontext = $this->getDataGenerator()->create_block(
            'html',
            ['parentcontextid' => \context_course::instance($course->id)->id]
        );
        $blockcoursemodulecontext = $this->getDataGenerator()->create_block(
            'html',
            ['parentcontextid' => \context_module::instance($pagecoursemodule->cmid)->id]
        );

        $coursecontext = \context_course::instance($course->id);
        $course2context = \context_course::instance($course2->id);
        $coursecatcontext = \context_coursecat::instance($coursecat->id);
        $subcoursecatcontext = \context_coursecat::instance($subcoursecat->id);
        $pagecoursemodulecontext = \context_module::instance($pagecoursemodule->cmid);
        $pagecoursemodule2context = \context_module::instance($pagecoursemodule2->cmid);
        $blockusercontextcontext = \context_block::instance($blockusercontext->id);
        $blocksystemcontextcontext = \context_block::instance($blocksystemcontext->id);
        $blockcoursecontextcontext = \context_block::instance($blockcoursecontext->id);
        $blockcoursemodulecontextcontext = \context_block::instance($blockcoursemodulecontext->id);

        $this->assertEquals($coursecontext->id, ai_manager_utils::find_closest_parent_course_context($coursecontext)->id);
        $this->assertNull(ai_manager_utils::find_closest_parent_course_context($coursecatcontext));
        $this->assertNull(ai_manager_utils::find_closest_parent_course_context($subcoursecatcontext));
        $this->assertEquals($coursecontext->id, ai_manager_utils::find_closest_parent_course_context($pagecoursemodulecontext)->id);
        $this->assertNotEquals(
            $coursecontext->id,
            ai_manager_utils::find_closest_parent_course_context($pagecoursemodule2context)->id
        );
        $this->assertEquals(
            $course2context->id,
            ai_manager_utils::find_closest_parent_course_context($pagecoursemodule2context)->id
        );
        $this->assertNull(ai_manager_utils::find_closest_parent_course_context($blockusercontextcontext));
        $this->assertNull(ai_manager_utils::find_closest_parent_course_context($blocksystemcontextcontext));
        $this->assertNull(ai_manager_utils::find_closest_parent_course_context($blocksystemcontextcontext));
        $this->assertEquals(
            $coursecontext->id,
            ai_manager_utils::find_closest_parent_course_context($blockcoursecontextcontext)->id
        );
        $this->assertEquals(
            $coursecontext->id,
            ai_manager_utils::find_closest_parent_course_context($blockcoursemodulecontextcontext)->id
        );
    }

    /**
     * Test for the get_log_entries method.
     *
     * @covers \local_ai_manager\ai_manager_utils::get_log_entries
     */
    public function test_get_log_entries(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $record = new stdClass();
        $record->userid = $user->id;
        $record->value = 3.8;
        $record->purpose = 'chat';
        $record->model = 'testmodel';
        $record->modelinfo = 'testmodel-3.5';
        $record->prompttext = 'some prompt 1';
        $record->promptcompletion = 'some prompt response 1';
        $record->component = 'block_ai_chat';
        $record->contextid = 12;
        $record->itemid = 5;
        $record->timecreated = time();
        $DB->insert_record('local_ai_manager_request_log', $record);

        $record = new stdClass();
        $record->userid = $user->id;
        $record->value = 2.3;
        $record->purpose = 'chat';
        $record->model = 'testmodel';
        $record->modelinfo = 'testmodel-3.5';
        $record->prompttext = 'some prompt 2';
        $record->promptcompletion = 'some prompt response 2';
        $record->component = 'block_ai_chat';
        $record->contextid = 13;
        $record->itemid = 7;
        $record->timecreated = time();
        $DB->insert_record('local_ai_manager_request_log', $record);

        $record = new stdClass();
        $record->userid = $user->id;
        $record->value = 1.2;
        $record->purpose = 'translate';
        $record->model = 'testmodel';
        $record->modelinfo = 'testmodel-3.5';
        $record->prompttext = 'some prompt 3';
        $record->promptcompletion = 'some prompt response 3';
        $record->component = 'tiny_ai';
        $record->contextid = 12;
        $record->itemid = 5;
        $record->timecreated = time();
        $DB->insert_record('local_ai_manager_request_log', $record);

        $record = new stdClass();
        $record->userid = $user->id;
        $record->value = 1.2;
        $record->purpose = 'itt';
        $record->model = 'testmodel';
        $record->modelinfo = 'testmodel-3.5';
        $record->prompttext = 'some prompt 4';
        $record->promptcompletion = 'some prompt response 4';
        $record->component = 'tiny_ai';
        $record->contextid = 12;
        $record->itemid = 5;
        $record->timecreated = time();
        $DB->insert_record('local_ai_manager_request_log', $record);

        // Same as the first, but now with different user.
        $record = new stdClass();
        $record->userid = $user2->id;
        $record->value = 1.2;
        $record->purpose = 'chat';
        $record->model = 'testmodel';
        $record->modelinfo = 'testmodel-3.5';
        $record->prompttext = 'some prompt 5';
        $record->promptcompletion = 'some prompt response 5';
        $record->component = 'block_ai_chat';
        $record->contextid = 12;
        $record->itemid = 5;
        $record->timecreated = time();
        $DB->insert_record('local_ai_manager_request_log', $record);

        // Same as the first, but now with different itemid.
        $record = new stdClass();
        $record->userid = $user2->id;
        $record->value = 1.2;
        $record->purpose = 'chat';
        $record->model = 'testmodel';
        $record->modelinfo = 'testmodel-3.5';
        $record->prompttext = 'some prompt 6';
        $record->promptcompletion = 'some prompt response 6';
        $record->component = 'block_ai_chat';
        $record->contextid = 12;
        $record->itemid = 10;
        $record->timecreated = time();
        $DB->insert_record('local_ai_manager_request_log', $record);

        // Same as the first, but now with deleted.
        $record = new stdClass();
        $record->userid = $user2->id;
        $record->value = 1.2;
        $record->purpose = 'chat';
        $record->model = 'testmodel';
        $record->modelinfo = 'testmodel-3.5';
        $record->prompttext = 'some prompt 7';
        $record->promptcompletion = 'some prompt response 7';
        $record->component = 'block_ai_chat';
        $record->contextid = 12;
        $record->itemid = 5;
        $record->deleted = 1;
        $record->timecreated = time();
        $DB->insert_record('local_ai_manager_request_log', $record);

        $logentries = ai_manager_utils::get_log_entries('block_ai_chat', 12);
        $this->assertCount(4, $logentries);
        $this->assertCount(1, array_filter($logentries, fn($logentry) => $logentry->prompttext === 'some prompt 1'));
        $this->assertCount(1, array_filter($logentries, fn($logentry) => $logentry->prompttext === 'some prompt 5'));
        $this->assertCount(1, array_filter($logentries, fn($logentry) => $logentry->prompttext === 'some prompt 6'));
        $this->assertCount(1, array_filter($logentries, fn($logentry) => $logentry->prompttext === 'some prompt 7'));

        $logentries = ai_manager_utils::get_log_entries('block_ai_chat', 13);
        $this->assertCount(1, $logentries);
        $this->assertCount(1, array_filter($logentries, fn($logentry) => $logentry->prompttext === 'some prompt 2'));

        $logentries = ai_manager_utils::get_log_entries('tiny_ai', 13);
        $this->assertCount(0, $logentries);

        $logentries = ai_manager_utils::get_log_entries('tiny_ai', 12);
        $this->assertCount(2, $logentries);
        $this->assertCount(1, array_filter($logentries, fn($logentry) => $logentry->prompttext === 'some prompt 3'));
        $logentries = ai_manager_utils::get_log_entries('tiny_ai', 12, 0, 0, true, '*', ['translate']);
        $this->assertCount(1, $logentries);
        $this->assertCount(1, array_filter($logentries, fn($logentry) => $logentry->prompttext === 'some prompt 3'));

        $logentries = ai_manager_utils::get_log_entries('tiny_ai', 12, 0, 0, true, '*', ['chat']);
        $this->assertCount(0, $logentries);

        $logentries = ai_manager_utils::get_log_entries('block_ai_chat', 12, 0, 0, false);
        $this->assertCount(3, $logentries);
        // Should not contain the deleted entry.
        $this->assertCount(0, array_filter($logentries, fn($logentry) => $logentry->prompttext === 'some prompt 7'));

        // Finally, test selection of database fields.
        $logentries = ai_manager_utils::get_log_entries('block_ai_chat', 12, $user->id, 5, true, 'id,prompttext,promptcompletion');
        $this->assertCount(1, $logentries);
        $entry = reset($logentries);
        $this->assertTrue(property_exists($entry, 'prompttext'));
        $this->assertTrue(property_exists($entry, 'promptcompletion'));
        $this->assertFalse(property_exists($entry, 'component'));
    }

    /**
     * Test the function that calculates the general availability of frontend tools.
     *
     * @covers \local_ai_manager\ai_manager_utils::get_ai_config
     * @covers \local_ai_manager\ai_manager_utils::determine_availability
     */
    public function test_determine_availability(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['institution' => '1234']);
        $course = $this->getDataGenerator()->create_course();

        $block = $this->getDataGenerator()->create_block(
            'html',
            ['parentcontextid' => \context_course::instance($course->id)->id]
        );

        // First of all, set up everything in a way that a request could in theory be made, so no restrictions apply.
        $aiuserroleid = $this->getDataGenerator()->create_role(['shortname' => 'aiuser']);
        role_assign($aiuserroleid, $user->id, SYSCONTEXTID);
        assign_capability('local/ai_manager:use', CAP_ALLOW, $aiuserroleid, SYSCONTEXTID);
        $this->setUser($user);
        $tenant = \core\di::get(\local_ai_manager\local\tenant::class);
        $this->assertTrue($tenant->is_tenant_allowed());

        $configmanager = \core\di::get(\local_ai_manager\local\config_manager::class);
        $configmanager->set_config('tenantenabled', true);

        $userinfo = new userinfo($user->id);
        // We only test the case when the confirmation setting is enabled.
        set_config('requireconfirmtou', 1, 'local_ai_manager');
        $userinfo->set_confirmed(true);
        $userinfo->set_role(userinfo::ROLE_BASIC);
        $userinfo->set_locked(false);
        $userinfo->set_scope(userinfo::SCOPE_EVERYWHERE);
        $userinfo->store();

        $userusage = new userusage(\core\di::get(connector_factory::class)->get_purpose_by_purpose_string('chat'), $user->id);
        $userusage->set_currentusage(10);
        $userusage->store();

        $configmanager->set_config('chat_max_requests_basic', 50);
        $blockcontextid = \context_block::instance($block->id)->id;

        $availability = ai_manager_utils::get_ai_config($user, $blockcontextid, null, ['chat'])['availability'];
        $this->assertEquals($availability['available'], ai_manager_utils::AVAILABILITY_AVAILABLE);

        // Now one by one introduce one "problem", check the correct state and reset the "problem".
        unassign_capability('local/ai_manager:use', $aiuserroleid, SYSCONTEXTID);
        assign_capability('local/ai_manager:use', CAP_PROHIBIT, $aiuserroleid, SYSCONTEXTID);
        $availability = ai_manager_utils::get_ai_config($user, $blockcontextid, null, ['chat'])['availability'];
        $this->assertEquals($availability['available'], ai_manager_utils::AVAILABILITY_HIDDEN);
        unassign_capability('local/ai_manager:use', $aiuserroleid, SYSCONTEXTID);
        assign_capability('local/ai_manager:use', CAP_ALLOW, $aiuserroleid, SYSCONTEXTID);

        set_config('restricttenants', 1, 'local_ai_manager');
        $availability = ai_manager_utils::get_ai_config($user, $blockcontextid, null, ['chat'])['availability'];
        $this->assertEquals($availability['available'], ai_manager_utils::AVAILABILITY_HIDDEN);
        set_config('restricttenants', 0, 'local_ai_manager');

        $configmanager->set_config('tenantenabled', false);
        $availability = ai_manager_utils::get_ai_config($user, $blockcontextid, null, ['chat'])['availability'];
        $this->assertEquals($availability['available'], ai_manager_utils::AVAILABILITY_HIDDEN);
        set_config('restrictedtenants', '', 'local_ai_manager');
        $configmanager->set_config('tenantenabled', true);

        $userinfo->set_scope(userinfo::SCOPE_COURSES_ONLY);
        $userinfo->store();
        $availability = ai_manager_utils::get_ai_config($user, SYSCONTEXTID, null, ['chat'])['availability'];
        $this->assertEquals($availability['available'], ai_manager_utils::AVAILABILITY_HIDDEN);
        $userinfo->set_scope(userinfo::SCOPE_EVERYWHERE);
        $userinfo->store();

        $userinfo->set_locked(true);
        $userinfo->store();
        $availability = ai_manager_utils::get_ai_config($user, $blockcontextid, null, ['chat'])['availability'];
        $this->assertEquals($availability['available'], ai_manager_utils::AVAILABILITY_DISABLED);
        $userinfo->set_locked(false);
        $userinfo->store();

        $userinfo->set_confirmed(false);
        $userinfo->store();
        $availability = ai_manager_utils::get_ai_config($user, $blockcontextid, null, ['chat'])['availability'];
        $this->assertEquals($availability['available'], ai_manager_utils::AVAILABILITY_DISABLED);
        $userinfo->set_confirmed(true);
        $userinfo->store();

        // Checks that cause the state "hidden" should win over checks that cause the state "disabled" if both checks apply.
        $userinfo->set_locked(true);
        $userinfo->set_scope(userinfo::SCOPE_COURSES_ONLY);
        $userinfo->store();
        $availability = ai_manager_utils::get_ai_config($user, SYSCONTEXTID, null, ['chat'])['availability'];
        $this->assertEquals(ai_manager_utils::AVAILABILITY_HIDDEN, $availability['available']);
        $userinfo->set_locked(false);
        $userinfo->set_scope(userinfo::SCOPE_EVERYWHERE);
        $userinfo->store();

        $userinfo->set_confirmed(false);
        $userinfo->set_scope(userinfo::SCOPE_COURSES_ONLY);
        $userinfo->store();
        $availability = ai_manager_utils::get_ai_config($user, SYSCONTEXTID, null, ['chat'])['availability'];
        $this->assertEquals(ai_manager_utils::AVAILABILITY_HIDDEN, $availability['available']);
        $userinfo->set_confirmed(true);
        $userinfo->set_scope(userinfo::SCOPE_EVERYWHERE);
        $userinfo->store();
    }

    /**
     * Test the function that calculates the availability of frontend tools for certain purposes.
     *
     * @covers \local_ai_manager\ai_manager_utils::get_ai_config
     * @covers \local_ai_manager\ai_manager_utils::determine_purposes_availability
     */
    public function test_determine_purposes_availability(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['institution' => '1234']);
        $course = $this->getDataGenerator()->create_course();

        $block = $this->getDataGenerator()->create_block(
            'html',
            ['parentcontextid' => \context_course::instance($course->id)->id]
        );

        // First of all, set up everything in a way that a request could in theory be made, so no restrictions apply.
        $aiuserroleid = $this->getDataGenerator()->create_role(['shortname' => 'aiuser']);
        role_assign($aiuserroleid, $user->id, SYSCONTEXTID);
        assign_capability('local/ai_manager:use', CAP_ALLOW, $aiuserroleid, SYSCONTEXTID);
        $this->setUser($user);
        $tenant = \core\di::get(\local_ai_manager\local\tenant::class);
        $this->assertTrue($tenant->is_tenant_allowed());

        $configmanager = \core\di::get(\local_ai_manager\local\config_manager::class);
        $configmanager->set_config('tenantenabled', true);

        $userinfo = new userinfo($user->id);
        $userinfo->set_confirmed(true);
        $userinfo->set_role(userinfo::ROLE_BASIC);
        $userinfo->set_locked(false);
        $userinfo->set_scope(userinfo::SCOPE_EVERYWHERE);
        $userinfo->store();

        $userusage = new userusage(\core\di::get(connector_factory::class)->get_purpose_by_purpose_string('chat'), $user->id);
        $userusage->set_currentusage(10);
        $userusage->store();

        $configmanager->set_config('chat_max_requests_basic', 50);
        $blockcontextid = \context_block::instance($block->id)->id;

        $factory = \core\di::get(\local_ai_manager\local\connector_factory::class);
        $instance = $factory->get_new_instance('chatgpt');
        $instance->set_model_id_from_name('gpt-4o');
        $instance->store();

        $configmanager->set_config(base_purpose::get_purpose_tool_config_key('chat', userinfo::ROLE_BASIC), $instance->get_id());

        $hookmanager = \core\di::get(\core\hook\manager::class);
        $hookmanager->phpunit_redirect_hook(
            additional_user_restriction::class,
            function ($hook) {
                $hook->set_access_allowed(true);
            }
        );

        $chatpurposeconfig = ai_manager_utils::get_ai_config($user, $blockcontextid, null, ['chat'])['purposes'][0];
        $this->assertEquals($chatpurposeconfig['available'], ai_manager_utils::AVAILABILITY_AVAILABLE);

        // Just a general test if we have a config for all purposes if we do not specify a certain one.
        $purposesconfig = ai_manager_utils::get_ai_config($user, $blockcontextid)['purposes'];
        $this->assertCount(count(base_purpose::get_all_purposes()), $purposesconfig);
        foreach (base_purpose::get_all_purposes() as $purpose) {
            $purposeconfig =
                array_values(array_filter($purposesconfig, fn($purposeconfig) => $purposeconfig['purpose'] === $purpose))[0];
            $this->assertTrue(
                in_array(
                    $purposeconfig['available'],
                    [ai_manager_utils::AVAILABILITY_AVAILABLE, ai_manager_utils::AVAILABILITY_HIDDEN,
                        ai_manager_utils::AVAILABILITY_DISABLED]
                )
            );
        }

        // Now introduce "problems" one by one and check the correct state. After that reset
        // "the problem".
        // At first, simulate that for the role no AI tool has been configured.
        $configmanager->unset_config(base_purpose::get_purpose_tool_config_key('chat', userinfo::ROLE_BASIC));
        $chatpurposeconfig = ai_manager_utils::get_ai_config($user, $blockcontextid, null, ['chat'])['purposes'][0];
        $this->assertEquals($chatpurposeconfig['available'], ai_manager_utils::AVAILABILITY_DISABLED);
        $configmanager->set_config(base_purpose::get_purpose_tool_config_key('chat', userinfo::ROLE_BASIC), $instance->get_id());

        \local_ai_manager\plugininfo\aitool::enable_plugin('chatgpt', false);
        \core\di::set(connector_factory::class, new connector_factory($configmanager));
        $chatpurposeconfig = ai_manager_utils::get_ai_config($user, $blockcontextid, null, ['chat'])['purposes'][0];
        $this->assertEquals($chatpurposeconfig['available'], ai_manager_utils::AVAILABILITY_DISABLED);
        \local_ai_manager\plugininfo\aitool::enable_plugin('chatgpt', true);
        \core\di::set(connector_factory::class, new connector_factory($configmanager));

        $userusage->set_currentusage(100);
        $userusage->store();
        $chatpurposeconfig = ai_manager_utils::get_ai_config($user, $blockcontextid, null, ['chat'])['purposes'][0];
        $this->assertEquals($chatpurposeconfig['available'], ai_manager_utils::AVAILABILITY_DISABLED);
        $userusage->set_currentusage(10);
        $userusage->store();

        // Disable purpose for the basic role.
        $configmanager->set_config('chat_max_requests_basic', 0);
        $chatpurposeconfig = ai_manager_utils::get_ai_config($user, $blockcontextid, null, ['chat'])['purposes'][0];
        $this->assertEquals($chatpurposeconfig['available'], ai_manager_utils::AVAILABILITY_DISABLED);
        $configmanager->set_config('chat_max_requests_basic', 50);

        // Test the hook.
        $hookmanager->phpunit_stop_redirections();
        $hookmanager->phpunit_redirect_hook(additional_user_restriction::class, function ($hook) {
            $hook->set_access_allowed(false, 403, 'You are not allowed!');
        });
        $chatpurposeconfig = ai_manager_utils::get_ai_config($user, $blockcontextid, null, ['chat'])['purposes'][0];
        $this->assertEquals($chatpurposeconfig['available'], ai_manager_utils::AVAILABILITY_HIDDEN);

        // The hook must also hide the purpose if a config check would mark it as disabled,
        // for example because the user has exceeded the quota.
        $userusage->set_currentusage(100);
        $userusage->store();
        $chatpurposeconfig = ai_manager_utils::get_ai_config($user, $blockcontextid, null, ['chat'])['purposes'][0];
        $this->assertEquals($chatpurposeconfig['available'], ai_manager_utils::AVAILABILITY_HIDDEN);
        $userusage->set_currentusage(10);
        $userusage->store();

        $hookmanager->phpunit_stop_redirections();
        $hookmanager->phpunit_redirect_hook(additional_user_restriction::class, function ($hook) {
            $hook->set_access_allowed(true);
        });

        // Test if we receive a valid answer for disabled purpose subplugins.
        aipurpose::enable_plugin('chat', false);
        $chatpurposeconfig = ai_manager_utils::get_ai_config($user, $blockcontextid, null, ['chat'])['purposes'][0];
        $this->assertEquals($chatpurposeconfig['available'], ai_manager_utils::AVAILABILITY_HIDDEN);
    }

    /**
     * Tests the utility function to get the purpose usages information.
     *
     * It basically tests the proper formatting of the array, which then will be injected into the
     * corresponding template.
     *
     * @covers \local_ai_manager\ai_manager_utils::get_purposes_usage_info
     */
    public function test_get_purposes_usage_info(): void {
        $this->redirectHook(\local_ai_manager\hook\purpose_usage::class, function ($hook) {
            $hook->set_component_displayname('testcomponent1', 'displaynamecomponent1');
            $hook->set_component_displayname('testcomponent2', 'displaynamecomponent2');
            $hook->add_purpose_usage_description('chat', 'testcomponent1', 'testcomponent1 description first place chat');
            $hook->add_purpose_usage_description('chat', 'testcomponent1', 'testcomponent1 description second place chat');
            $hook->add_purpose_usage_description('chat', 'testcomponent2', 'testcomponent2 description first place chat');
            $hook->add_purpose_usage_description('chat', 'testcomponent2', 'testcomponent2 description second place chat');
            $hook->add_purpose_usage_description(
                'translate',
                'testcomponent1',
                'description of the first place for translating'
            );
        });

        $expected = ['purposes' => []];
        $connectorfactory = \core\di::get(connector_factory::class);
        foreach (\local_ai_manager\plugininfo\aipurpose::get_enabled_plugins() as $purpose) {
            $purposeinstance = $connectorfactory->get_purpose_by_purpose_string($purpose);
            $purposearray = [
                'purposename' => $purpose,
                'purposedisplayname' => get_string('pluginname', 'aipurpose_' . $purpose),
                'purposedescription' => $purposeinstance->get_description(),
            ];
            if ($purpose === 'chat') {
                $purposearray['components'] = [
                    [
                        'component' => 'testcomponent1',
                        'componentdisplayname' => 'displaynamecomponent1',
                        'placedescriptions' => [
                            [
                                'description' => 'testcomponent1 description first place chat',
                            ],
                            [
                                'description' => 'testcomponent1 description second place chat',
                            ],
                        ],
                    ],
                    [
                        'component' => 'testcomponent2',
                        'componentdisplayname' => 'displaynamecomponent2',
                        'placedescriptions' => [
                            [
                                'description' => 'testcomponent2 description first place chat',
                            ],
                            [
                                'description' => 'testcomponent2 description second place chat',
                            ],
                        ],
                    ],
                ];
            } else if ($purpose === 'translate') {
                $purposearray['components'][] =
                    [
                        'component' => 'testcomponent1',
                        'componentdisplayname' => 'displaynamecomponent1',
                        'placedescriptions' => [
                            [
                                'description' => 'description of the first place for translating',
                            ],

                        ],
                    ];
            }
            $expected['purposes'][] = $purposearray;
        }

        $this->assertEquals($expected, ai_manager_utils::get_purposes_usage_info());
    }

    /**
     * Creates a user of the given tenant, logs the user in and starts with a fresh DI container.
     *
     * @param string $institution the institution field of the user, used as the tenant identifier
     * @param bool $managetenants whether the user gets the local/ai_manager:managetenants capability
     * @return stdClass the created user
     */
    private function setup_tenant_user(string $institution, bool $managetenants = false): stdClass {
        $user = $this->getDataGenerator()->create_user(['institution' => $institution]);
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/ai_manager:use', CAP_ALLOW, $roleid, SYSCONTEXTID);
        if ($managetenants) {
            assign_capability('local/ai_manager:managetenants', CAP_ALLOW, $roleid, SYSCONTEXTID);
        }
        role_assign($roleid, $user->id, SYSCONTEXTID);
        $this->setUser($user);
        \core\di::reset_container();
        return $user;
    }

    /**
     * Tests that a user cannot request the general info of a tenant the user does not belong to or manage.
     *
     * @covers \local_ai_manager\ai_manager_utils::get_ai_info
     */
    public function test_get_ai_info_denies_foreign_tenant(): void {
        $this->resetAfterTest();
        $this->setup_tenant_user('schoola');
        $tenantbefore = \core\di::get(tenant::class);

        $exceptionthrown = false;
        try {
            ai_manager_utils::get_ai_info('schoolb');
        } catch (\moodle_exception $exception) {
            $exceptionthrown = true;
            $expectedmessage = get_string('exception_tenantaccessdenied', 'local_ai_manager', 'schoolb');
            $this->assertEquals($expectedmessage, $exception->getMessage());
        }
        $this->assertTrue($exceptionthrown);
        $this->assertSame($tenantbefore, \core\di::get(tenant::class));
    }

    /**
     * Tests that the own tenant and no tenant at all are accepted and that the tenant of the current user stays bound.
     *
     * @covers \local_ai_manager\ai_manager_utils::get_ai_info
     * @covers \local_ai_manager\ai_manager_utils::get_ai_config
     */
    public function test_own_tenant_is_accepted(): void {
        $this->resetAfterTest();
        $user = $this->setup_tenant_user('schoola');
        $tenantbefore = \core\di::get(tenant::class);

        $this->assertEquals(ai_manager_utils::get_ai_info(), ai_manager_utils::get_ai_info('schoola'));
        $this->assertEquals(
            ai_manager_utils::get_ai_config($user, SYSCONTEXTID, null, ['chat']),
            ai_manager_utils::get_ai_config($user, SYSCONTEXTID, 'schoola', ['chat'])
        );
        $this->assertSame($tenantbefore, \core\di::get(tenant::class));
    }

    /**
     * Tests that a user cannot request the config of a foreign tenant and that no tenant dependent binding is changed.
     *
     * @covers \local_ai_manager\ai_manager_utils::get_ai_config
     */
    public function test_get_ai_config_denies_foreign_tenant(): void {
        $this->resetAfterTest();
        $user = $this->setup_tenant_user('schoola');
        $bindingsbefore = $this->get_tenant_bindings();

        try {
            ai_manager_utils::get_ai_config($user, SYSCONTEXTID, 'schoolb', ['chat']);
            $this->fail('Accessing a foreign tenant must not be possible');
        } catch (\moodle_exception $exception) {
            $this->assertEquals('exception_tenantaccessdenied', $exception->errorcode);
        }
        $this->assertSame($bindingsbefore, $this->get_tenant_bindings());
    }

    /**
     * Tests that users managing all tenants get the config of the requested tenant and that afterwards the bindings of the
     * own tenant are in place again, so later web services of the same request are not served with the foreign tenant.
     *
     * @covers \local_ai_manager\ai_manager_utils::get_ai_config
     * @covers \local_ai_manager\ai_manager_utils::get_ai_info
     */
    public function test_managetenants_user_can_access_foreign_tenant(): void {
        $this->resetAfterTest();
        $user = $this->setup_tenant_user('schoola', true);
        // Only the foreign tenant is enabled, so the availability shows which tenant configuration has been used.
        (new config_manager(new tenant('schoolb')))->set_config('tenantenabled', 1);
        $bindingsbefore = $this->get_tenant_bindings();

        $availability = ai_manager_utils::get_ai_config($user, SYSCONTEXTID, 'schoolb', ['chat'])['availability'];
        $this->assertNotEquals(ai_manager_utils::AVAILABILITY_HIDDEN, $availability['available']);
        $this->assertSame($bindingsbefore, $this->get_tenant_bindings());
        // The own tenant is not enabled.
        $availability = ai_manager_utils::get_ai_config($user, SYSCONTEXTID, null, ['chat'])['availability'];
        $this->assertEquals(ai_manager_utils::AVAILABILITY_HIDDEN, $availability['available']);

        $info = ai_manager_utils::get_ai_info('schoolb');
        $this->assertArrayHasKey('tools', $info);
        $this->assertSame($bindingsbefore, $this->get_tenant_bindings());
    }

    /**
     * Tests that users managing all tenants are not locked out by a malformed tenant field value of their own profile.
     *
     * @covers \local_ai_manager\ai_manager_utils::get_ai_info
     * @covers \local_ai_manager\ai_manager_utils::get_ai_config
     */
    public function test_managetenants_user_with_malformed_own_tenant(): void {
        $this->resetAfterTest();
        $user = $this->setup_tenant_user(' malformed tenant!', true);

        $this->assertArrayHasKey('tools', ai_manager_utils::get_ai_info('schoolb'));

        // The tenant of the current user is needed for restoring the bindings afterwards, so this case fails deliberately.
        $this->expectException(\invalid_parameter_exception::class);
        ai_manager_utils::get_ai_config($user, SYSCONTEXTID, 'schoolb', ['chat']);
    }

    /**
     * Tests that users who may manage a tenant because of the tenant context provided by a plugin get access to this tenant,
     * even if they do not belong to it.
     *
     * @covers \local_ai_manager\ai_manager_utils::get_ai_info
     */
    public function test_tenant_manager_by_tenant_context_can_access_tenant(): void {
        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $categorycontext = \context_coursecat::instance($category->id);
        $this->redirectHook(
            \local_ai_manager\hook\custom_tenant::class,
            function (\local_ai_manager\hook\custom_tenant $customtenant) use ($categorycontext) {
                $customtenant->set_tenant_context($categorycontext);
            }
        );
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/ai_manager:manage', CAP_ALLOW, $roleid, SYSCONTEXTID);

        // A user without the manager role in the tenant context is denied.
        $this->setup_tenant_user('schoola');
        try {
            ai_manager_utils::get_ai_info('schoolb');
            $this->fail('Accessing a foreign tenant must not be possible');
        } catch (\moodle_exception $exception) {
            $this->assertEquals('exception_tenantaccessdenied', $exception->errorcode);
        }

        $manager = $this->setup_tenant_user('schoola');
        role_assign($roleid, $manager->id, $categorycontext->id);
        \core\di::reset_container();
        $this->assertArrayHasKey('tools', ai_manager_utils::get_ai_info('schoolb'));
    }

    /**
     * Returns the currently bound tenant dependent objects of the DI container.
     *
     * @return array the bound objects, keyed by their class name
     */
    private function get_tenant_bindings(): array {
        return [
            tenant::class => \core\di::get(tenant::class),
            config_manager::class => \core\di::get(config_manager::class),
            connector_factory::class => \core\di::get(connector_factory::class),
            access_manager::class => \core\di::get(access_manager::class),
        ];
    }
}

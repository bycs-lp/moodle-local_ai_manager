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

namespace aipurpose_agent;

use aipurpose_agent\purpose;
use aitool_chatgpt\instance;
use context_system;
use GuzzleHttp\Psr7\Stream;
use local_ai_manager\ai_manager_utils;
use local_ai_manager\local\config_manager;
use local_ai_manager\local\connector_factory;
use local_ai_manager\local\prompt_response;
use local_ai_manager\local\request_response;
use local_ai_manager\local\tenant;
use local_ai_manager\local\usage;
use local_ai_manager\local\userinfo;
use local_ai_manager\local\userusage;
use local_ai_manager\manager;
use local_ai_manager\plugininfo\aitool;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/my/lib.php');

/**
 * Unit tests for the blockinstances_cleanup_helper class.
 *
 * @package   local_mbscleanup
 * @copyright 2021 Paola Maneggia, ISB Bayern
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class purpose_test extends \advanced_testcase {

    /**
     * Tests the moving of conversations from the user context ai_chat block instances to the system instance.
     *
     * @covers \local_mbscleanup\local\blockinstances_cleanup_helper::move_chat_conversations_to_system_block
     */
    public function test_purpose_perfom_request(): void {
        global $DB, $CFG;

        $this->resetAfterTest();
        $correctaichatsystemblock = new stdClass();
        $correctaichatsystemblock->blockname = 'ai_chat';
        $correctaichatsystemblock->parentcontextid = SYSCONTEXTID;
        $correctaichatsystemblock->showinsubcontexts = 0;
        $correctaichatsystemblock->requiredbytheme = 0;
        $correctaichatsystemblock->pagetypepattern = '';
        $correctaichatsystemblock->subpagepattern = '';
        $correctaichatsystemblock->defaultregion = '';
        $correctaichatsystemblock->defaultweight = '';
        $correctaichatsystemblock->configdata = '';
        $correctaichatsystemblock->timecreated = time();
        $correctaichatsystemblock->timemodified = $correctaichatsystemblock->timecreated;
        $correctaichatsystemblockid = $DB->insert_record('block_instances', $correctaichatsystemblock);
        $correctaichatsystemblockcontext = \context_block::instance($correctaichatsystemblockid);

        // Now also create user contexts.
        $user1 = $this->getDataGenerator()->create_user();

        // Setup the AI Manager.
        $this->setup_ai_manager($user1);
        $this->setUser($user1);
        $manager = new manager('chat');

        // Is conversationid still needed?
        $conversationid = ai_manager_utils::get_next_free_itemid('block_ai_chat', $correctaichatsystemblockcontext->id);

        $options = file_get_contents($CFG->dirroot.'/local/ai_manager/purposes/agent/tests/fixtures/options.json');
        $agentoptions = [
                'agentoptions' => json_decode($options, true)
        ];

        $result = $manager->perform_request('test', 'block_ai_chat',
                $correctaichatsystemblockcontext->id,
                $agentoptions);
    }

    /**
     * Helper function to set up all the necessary things to be able to perform a mock chat request with the local_ai_manager.
     *
     * @param stdClass $user The user, which should be set up for performing the mock chat request
     */
    private function setup_ai_manager(\stdClass $user): void {
        global $DB, $CFG;

        // Faking some chat conversations is going to be a bit of work, but let's do it.
        $tenant = new tenant('1234');
        // Set the capability based on the $configuration.
        $systemcontext = context_system::instance();
        $aiuserrole = $DB->get_record('role', ['shortname' => 'aiuser']);
        if (empty($aiuserrole)) {
            $this->getDataGenerator()->create_role(['shortname' => 'aiuser']);
            $aiuserrole = $DB->get_record('role', ['shortname' => 'aiuser']);
        }
        role_assign($aiuserrole->id, $user->id, $systemcontext->id);
        assign_capability('local/ai_manager:use', CAP_ALLOW, $aiuserrole->id, $systemcontext->id);

        $configmanager = new config_manager($tenant);
        $configmanager->set_config('tenantenabled', 1);

        $userinfo = new userinfo($user->id);
        $userinfo->set_locked(false);
        $userinfo->set_confirmed(true);
        $userinfo->set_scope(userinfo::SCOPE_EVERYWHERE);
        $userinfo->set_role(userinfo::ROLE_BASIC);
        $userinfo->store();

        $configmanager->set_config('chat_max_requests_basic', 1000);

        $userusage = new userusage(\core\di::get(connector_factory::class)->get_purpose_by_purpose_string('chat'), $user->id);
        $userusage->set_currentusage(0);
        $userusage->store();

        $chatgptinstance = new instance();
        $chatgptinstance->set_model('gpt-4o');
        $chatgptinstance->set_connector('chatgpt');

        // Fake a stream object, because we will mock the method that access it anyway.
        $streamresponse = new Stream(fopen('php://temp', 'r+'));
        $requestresponse = request_response::create_from_result($streamresponse);

        // Fake usage object.
        $usage = new usage(50.0, 30.0, 20.0);
        // Fake prompt_response object.

        $responsetext = file_get_contents($CFG->dirroot.'/local/ai_manager/purposes/agent/tests/fixtures/response.txt');

        $promptresponse = prompt_response::create_from_result('gpt-4o', $usage, $responsetext);

        $chatgptconnector =
                $this->getMockBuilder('\aitool_chatgpt\connector')->setConstructorArgs([$chatgptinstance])->getMock();
        $chatgptconnector->expects($this->any())->method('make_request')->willReturn($requestresponse);
        $chatgptconnector->expects($this->any())->method('execute_prompt_completion')->willReturn($promptresponse);
        $connectorfactory =
                $this->getMockBuilder(connector_factory::class)->setConstructorArgs([$configmanager])->getMock();
        $connectorfactory->expects($this->any())->method('get_connector_by_purpose')->willReturn($chatgptconnector);
        $connectorfactory->expects($this->any())->method('get_connector_instance_by_purpose')->willReturn($chatgptinstance);

        $chatpurpose = new purpose();
        $connectorfactory->expects($this->any())->method('get_purpose_by_purpose_string')->willReturn($chatpurpose);
        \core\di::set(config_manager::class, $configmanager);
        \core\di::set(connector_factory::class, $connectorfactory);

        aitool::enable_plugin('agent', true);

        // We disable the hook here, so no other plugin is interfering.
        $this->redirectHook(\local_ai_manager\hook\additional_user_restriction::class, fn() => null);
    }

}
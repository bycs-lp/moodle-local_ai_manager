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

use core_privacy\local\request\approved_contextlist;
use local_ai_manager\local\data_wiper;
use local_ai_manager\local\tenant_config_output_utils;

/**
 * Test class for the ai_manager_utils functions.
 *
 * @package    local_ai_manager
 * @copyright  2025 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class userstats_table_test extends \advanced_testcase {
    /**
     * Test for ensuring that anonymized users are being displayed properly.
     *
     * @covers \local_ai_manager\table\userstats_table
     */
    public function test_anonymized_user(): void {
        global $DB, $SESSION;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $tenant = \core\di::get(\local_ai_manager\local\tenant::class);
        $privilegedrole = $this->getDataGenerator()->create_role(['shortname' => 'privilegedrole']);
        role_assign($privilegedrole, $user->id, SYSCONTEXTID);

        /** @var \local_ai_manager_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_ai_manager');
        $requestlogentry = $generator->create_request_log_entry();
        $contextlist = new approved_contextlist($user, 'local_ai_manager', [$requestlogentry->contextid]);
        \local_ai_manager\privacy\provider::delete_data_for_user($contextlist);
        $anonymizedrequestlog = $DB->get_record('local_ai_manager_request_log', ['id' => $requestlogentry->id]);
        $this->assertNull($anonymizedrequestlog->userid);
        $this->assertEquals(data_wiper::ANONYMIZE_STRING, $anonymizedrequestlog->prompttext);

        // Workaround for the dynamic tables we are using.
        $SESSION->local_ai_manager_tenant = $tenant;
        set_config('privilegedroles', $privilegedrole, 'local_ai_manager');
        $this->setAdminUser();
        tenant_config_output_utils::setup_tenant_config_page(new \moodle_url('/local/ai_manager/user_statistics.php'));
        $table = new \local_ai_manager\table\userstats_table('some_fake_id');

        // If anonymous entries are not being handled correctly, this will crash with an exception.
        ob_start();
        $table->out(30, false);
        $result = ob_get_clean();
        $this->assertStringContainsString(get_string('combinedanonymizedusers', 'local_ai_manager'), $result);
    }
}

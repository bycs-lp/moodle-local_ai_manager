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

namespace local_ai_manager\task;

use local_ai_manager\local\config_manager;
use local_ai_manager\local\tenant_factory;
use local_ai_manager\local\tenant;
use local_ai_manager\tests\task\testable_reset_user_usage;
use stdClass;

/**
 * Test class for reset user usage task.
 *
 * @package    local_ai_manager
 * @copyright  2024 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(reset_user_usage::class)]
final class reset_user_usage_test extends \advanced_testcase {
    /**
     * Tests the task.
     */
    public function test_execute(): void {
        global $DB;
        $this->resetAfterTest();
        // Should be the default anyway, but let's be safe here.
        set_config('tenantcolumn', 'institution');
        $tenant = new tenant(1234);
        $tenantfactory = new tenant_factory();
        $tenantfactory->set($tenant);
        $configmanager = new config_manager($tenantfactory);
        $configmanager->set_config('max_requests_period', 3 * DAYSECS);
        $tenant = new tenant(5678);
        $tenantfactory = new tenant_factory();
        $tenantfactory->set($tenant);
        $configmanager = new config_manager($tenantfactory);
        $configmanager->set_config('max_requests_period', 4 * DAYSECS);

        $currenttime = time();
        for ($i = 0; $i < 412; $i++) {
            $user = $this->getDataGenerator()->create_user(['institution' => '1234']);
            $record = new stdClass();
            $record->purpose = 'singleprompt';
            $record->currentusage = 5; // Anything but zero.
            $record->lastreset = $currenttime;
            $record->lastmodified = $currenttime;
            $record->userid = $user->id;
            $DB->insert_record('local_ai_manager_userusage', $record);
        }
        for ($i = 0; $i < 100; $i++) {
            $user = $this->getDataGenerator()->create_user(['institution' => '5678']);
            $record = new stdClass();
            $record->purpose = 'singleprompt';
            $record->currentusage = 5; // Anything but zero.
            $record->lastreset = $currenttime;
            $record->lastmodified = $currenttime;
            $record->userid = $user->id;
            $DB->insert_record('local_ai_manager_userusage', $record);
        }
        $this->assertCount(512, $DB->get_records_select('local_ai_manager_userusage', "currentusage != 0"));
        $this->assertCount(
            512,
            $DB->get_records_select('local_ai_manager_userusage', "lastreset = :currenttime", ['currenttime' => $currenttime])
        );

        // Set clock to 2 days which is below the configured value of 3 days.
        $clock = $this->mock_clock_with_frozen($currenttime + 2 * DAYSECS);
        \core\di::set('clock', $clock);
        ob_start();
        $task = new reset_user_usage();
        $task->execute();
        ob_end_clean();
        // Nothing should have happened, because last reset time is below the configured value for both tenants.
        $this->assertCount(512, $DB->get_records_select('local_ai_manager_userusage', "currentusage != 0"));
        $this->assertCount(
            512,
            $DB->get_records_select('local_ai_manager_userusage', "lastreset = :currenttime", ['currenttime' => $currenttime])
        );

        $clock = $this->mock_clock_with_frozen($currenttime + 3 * DAYSECS + 1);
        \core\di::set('clock', $clock);
        ob_start();
        $task = new reset_user_usage();
        $task->execute();
        ob_end_clean();

        // All records of tenant 1234 should have been reset, but not the ones for tenant 5678.
        $this->assertCount(412, $DB->get_records('local_ai_manager_userusage', ['currentusage' => 0]));
        $this->assertCount(412, $DB->get_records('local_ai_manager_userusage', ['lastreset' => $clock->time()]));

        // Now set the clock to be greater than both the times of tenant 1234 and tenant 5678.
        $clock = $this->mock_clock_with_frozen($currenttime + 4 * DAYSECS + 1);
        \core\di::set('clock', $clock);
        ob_start();
        $task = new reset_user_usage();
        $task->execute();
        ob_end_clean();

        // All records of tenant 1234 should have been reset, but not the ones for tenant 5678.
        $this->assertCount(512, $DB->get_records('local_ai_manager_userusage', ['currentusage' => 0]));
        $this->assertCount(100, $DB->get_records('local_ai_manager_userusage', ['lastreset' => $clock->time()]));
    }

    /**
     * Tests that the records are being reset in batches and that the task waits between all batches, also across tenants.
     */
    public function test_execute_batches_and_delay(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('tenantcolumn', 'institution');
        $lastreset = 1736935200;
        // Tenant 1234 needs 3 batches, tenant 5678 needs 2 batches (batch size of the testable task is 10).
        $recordcounts = ['1234' => 25, '5678' => 15];
        foreach ($recordcounts as $tenantidentifier => $count) {
            $tenantfactory = new tenant_factory();
            $tenantfactory->set(new tenant($tenantidentifier));
            (new config_manager($tenantfactory))->set_config('max_requests_period', DAYSECS);
            for ($i = 0; $i < $count; $i++) {
                $user = $this->getDataGenerator()->create_user(['institution' => $tenantidentifier]);
                $DB->insert_record('local_ai_manager_userusage', [
                    'purpose' => 'singleprompt',
                    'userid' => $user->id,
                    'currentusage' => 5,
                    'lastreset' => $lastreset,
                ]);
            }
        }

        $clock = $this->mock_clock_with_frozen($lastreset + DAYSECS + 1);
        $task = new testable_reset_user_usage();
        $this->expectOutputRegex('/tenant 1234 \(25 records\).*tenant 5678 \(15 records\)|'
            . 'tenant 5678 \(15 records\).*tenant 1234 \(25 records\)/s');
        $task->execute();

        $this->assertEquals(40, $DB->count_records('local_ai_manager_userusage', ['currentusage' => 0]));
        $this->assertEquals(40, $DB->count_records('local_ai_manager_userusage', ['lastreset' => $clock->time()]));
        // The task waits before every batch except the very first one, so the delay is not reset between tenants.
        $this->assertCount(4, $task->waits);
        foreach ($task->waits as $wait) {
            $this->assertGreaterThan(0, $wait);
            $this->assertLessThanOrEqual(1000000, $wait);
        }
    }
}

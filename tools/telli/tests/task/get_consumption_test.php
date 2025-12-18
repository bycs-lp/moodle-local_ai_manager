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

namespace aitool_telli\task;

use aitool_telli\local\apihandler;
use aitool_telli\local\utils;

/**
 * Unit tests for the get_consumption scheduled task.
 *
 * @package    aitool_telli
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \aitool_telli\task\get_consumption
 */
final class get_consumption_test extends \advanced_testcase {
    /**
     * Set up test configuration.
     */
    private function setup_config(): void {
        set_config('globalapikey', 'test_api_key', 'aitool_telli');
        set_config('baseurl', 'https://test.example.com', 'aitool_telli');
    }

    /**
     * Execute task and return output.
     *
     * @return string Task output
     */
    private function execute_task(): string {
        // Ensure clock is injected before creating the task.
        // The task constructor will fetch the clock from DI container.
        $task = new get_consumption();
        ob_start();
        $task->execute();
        return ob_get_clean();
    }

    /**
     * Set mock API response data.
     *
     * @param float $limit Limit in cent
     * @param float $remaining Remaining limit in cent
     */
    private function set_mock_data(float $limit, float $remaining): void {
        $mockresponse = json_encode([
            'limitInCent' => $limit,
            'remainingLimitInCent' => $remaining,
        ], JSON_THROW_ON_ERROR);

        $apiconnector = $this->getMockBuilder(\aitool_telli\local\apihandler::class)->onlyMethods(['get_usage_info'])->getMock();
        $apiconnector->expects($this->any())->method('get_usage_info')->willReturn($mockresponse);

        \core\di::set(\aitool_telli\local\apihandler::class, $apiconnector);
    }

    /**
     * Clean up after each test.
     */
    protected function tearDown(): void {
        \core\di::reset_container();
        parent::tearDown();
    }

    /**
     * Test that the task has the correct name.
     */
    public function test_get_name(): void {
        $task = new get_consumption();
        $this->assertEquals(get_string('getconsumptiontask', 'aitool_telli'), $task->get_name());
    }

    /**
     * Test that the task skips execution when API configuration is missing.
     */
    public function test_execute_skips_when_config_missing(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('globalapikey', '', 'aitool_telli');
        set_config('baseurl', '', 'aitool_telli');

        $output = $this->execute_task();

        $this->assertStringContainsString('Telli API configuration not set', $output);
        $this->assertEmpty($DB->get_records('aitool_telli_consumption'));
    }

    /**
     * Test storing initial consumption data and calculation accuracy.
     */
    public function test_execute_stores_initial_consumption(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setup_config();
        $this->set_mock_data(10000000, 9996954.58);

        $output = $this->execute_task();

        $records = $DB->get_records('aitool_telli_consumption', ['type' => 'current']);
        $this->assertCount(1, $records);

        $storedrecord = reset($records);
        $this->assertEquals('current', $storedrecord->type);
        // 10000000 - 9996954.58 = 3045.42, stored as float.
        $this->assertEqualsWithDelta(3045.42, (float)$storedrecord->value, 0.01);
        $this->assertGreaterThan(0, $storedrecord->timecreated);
        $this->assertStringContainsString('Stored current consumption', $output);

        // Verify calculation accuracy.
        $expectedconsumption = 10000000 - 9996954.58161529;
        $this->assertEqualsWithDelta(3045.41838471, $expectedconsumption, 0.00001);
        $this->assertEquals(3045, (int)$expectedconsumption);
    }

    /**
     * Data provider for reset detection tests.
     *
     * @return array Test scenarios
     */
    public static function reset_detection_provider(): array {
        return [
            'aggregate_reset_detected' => [
                'previous' => 5000,
                'newconsumption' => 1000,
                'expectaggregate' => true,
                'expectedaggregatevalue' => 5000,
                'expectedcurrent' => 1000,
                'expectedmessage' => 'aggregate limit was reset',
            ],
            'normal_increase_no_reset' => [
                'previous' => 3000,
                'newconsumption' => 3500,
                'expectaggregate' => false,
                'expectedaggregatevalue' => null,
                'expectedcurrent' => 3500,
                'expectedmessage' => null,
            ],
            'same_value_no_reset' => [
                'previous' => 3000,
                'newconsumption' => 3000,
                'expectaggregate' => false,
                'expectedaggregatevalue' => null,
                'expectedcurrent' => 3000,
                'expectedmessage' => null,
            ],
            'tiny_decrease_within_epsilon_no_reset' => [
                'previous' => 3000,
                'newconsumption' => 2999.99,
                'expectaggregate' => false,
                'expectedaggregatevalue' => null,
                'expectedcurrent' => 2999.99,
                'expectedmessage' => null,
            ],
            'significant_decrease_triggers_reset' => [
                'previous' => 3000,
                'newconsumption' => 2998,
                'expectaggregate' => true,
                'expectedaggregatevalue' => 3000,
                'expectedcurrent' => 2998,
                'expectedmessage' => 'aggregate limit was reset',
            ],
        ];
    }

    /**
     * Test aggregate reset detection logic.
     *
     * @dataProvider reset_detection_provider
     * @param float $previous Previous consumption value
     * @param float $newconsumption New consumption value
     * @param bool $expectaggregate Whether a aggregate record should be created
     * @param float|null $expectedaggregatevalue Expected aggregate record value
     * @param float $expectedcurrent Expected current record value
     * @param string|null $expectedmessage Expected log message
     */
    public function test_execute_reset_detection(
        float $previous,
        float $newconsumption,
        bool $expectaggregate,
        ?float $expectedaggregatevalue,
        float $expectedcurrent,
        ?string $expectedmessage
    ): void {
        global $DB;

        $this->resetAfterTest();
        $this->setup_config();

        $currenttime = time();
        $clock = $this->mock_clock_with_frozen($currenttime);
        \core\di::set(\core\clock::class, $clock);

        // Insert previous consumption record.
        $previousrecord = new \stdClass();
        $previousrecord->type = 'current';
        $previousrecord->value = $previous;
        $previousrecord->timecreated = $currenttime - MINSECS;
        $DB->insert_record('aitool_telli_consumption', $previousrecord);

        // Calculate remaining limit for desired consumption.
        $remaining = 10000000 - $newconsumption;
        $this->set_mock_data(10000000, $remaining);

        $output = $this->execute_task();

        // Verify aggregate record creation.
        $aggregaterecords = $DB->get_records('aitool_telli_consumption', ['type' => 'aggregate']);
        if ($expectaggregate) {
            $this->assertCount(1, $aggregaterecords);
            $aggregaterecord = reset($aggregaterecords);
            $this->assertEqualsWithDelta($expectedaggregatevalue, (float)$aggregaterecord->value, 0.01);
            $this->assertStringContainsString($expectedmessage, $output);
        } else {
            $this->assertEmpty($aggregaterecords);
            if ($expectedmessage !== null) {
                $this->assertStringNotContainsString($expectedmessage, $output);
            }
        }

        // Verify current record.
        $currentrecords = $DB->get_records('aitool_telli_consumption', ['type' => 'current'], 'id DESC', '*', 0, 1);
        $currentrecord = reset($currentrecords);
        $this->assertEqualsWithDelta($expectedcurrent, (float)$currentrecord->value, 0.01);
    }

    /**
     * Test that multiple resets create multiple aggregate records.
     */
    public function test_execute_multiple_aggregate_resets(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setup_config();

        // First execution: consumption at 5000.
        $this->set_mock_data(10000000, 9995000);
        $this->execute_task();
        $this->assertCount(0, $DB->get_records('aitool_telli_consumption', ['type' => 'aggregate']));
        $this->assertCount(1, $DB->get_records('aitool_telli_consumption', ['type' => 'current']));

        // Second execution: reset occurred, consumption at 2000.
        // Task creates aggregate record for 5000 (pre-reset value).
        $this->set_mock_data(10000000, 9998000);
        $this->execute_task();

        // Verify that there are two current records.
        $currentrecords = $DB->get_records('aitool_telli_consumption', ['type' => 'current'], 'id ASC');
        $this->assertCount(2, $currentrecords, 'Should have 2 current records after second execution');
        // Verify that there is only one aggregate record.
        $aggregaterecords = $DB->get_records('aitool_telli_consumption', ['type' => 'aggregate'], 'id ASC');
        $this->assertCount(1, $aggregaterecords, 'Should have 1 aggregate record after first reset');
        $firstaggregate = reset($aggregaterecords);
        $this->assertEquals(5000, $firstaggregate->value);

        // Third execution: consumption increases to 3000. There should be still only one aggregate record.
        $this->set_mock_data(10000000, 9997000);
        $this->execute_task();
        $this->assertCount(1, $DB->get_records('aitool_telli_consumption', ['type' => 'aggregate']));

        // Fourth execution: another reset, consumption at 1500.
        // Task creates aggregate record for 3000 (pre-reset value).
        $this->set_mock_data(10000000, 9998500);
        $this->execute_task();
        $aggregaterecords = $DB->get_records('aitool_telli_consumption', ['type' => 'aggregate'], 'id ASC');
        $this->assertCount(2, $aggregaterecords, 'Should have 2 aggregate records after second reset');

        // Verify the values are correct.
        $values = array_map('intval', array_column(array_values($aggregaterecords), 'value'));
        $this->assertEquals([5000, 3000], $values, 'aggregate values should match pre-reset consumption');
    }

    /**
     * Test that identical values with 6 decimal places do not trigger aggregate creation.
     */
    public function test_execute_identical_precise_float_values(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setup_config();

        // First execution: consumption at 3045.418385 (6 decimal places).
        $limit = 10000000;
        $remaining1 = 9996954.581615;
        $expectedconsumption = 3045.418385;

        $this->set_mock_data($limit, $remaining1);
        $output1 = $this->execute_task();

        // Verify first record.
        $records1 = $DB->get_records('aitool_telli_consumption', ['type' => 'current']);
        $this->assertCount(1, $records1);
        $record1 = reset($records1);
        $this->assertEqualsWithDelta($expectedconsumption, (float)$record1->value, 0.000001);

        // Verify no aggregate created.
        $aggregates1 = $DB->get_records('aitool_telli_consumption', ['type' => 'aggregate']);
        $this->assertCount(0, $aggregates1, 'No aggregate should exist after first execution');

        // Second execution: EXACT same values from API (simulating no consumption change).
        $remaining2 = 9996954.581615; // Identical to $remaining1.
        $this->set_mock_data($limit, $remaining2);
        $output2 = $this->execute_task();

        // Verify second current record was created.
        $records2 = $DB->get_records('aitool_telli_consumption', ['type' => 'current'], 'id ASC');
        $this->assertCount(2, $records2, 'Should have 2 current records after second execution');

        // Verify NO aggregate record was created (values are identical).
        $aggregates2 = $DB->get_records('aitool_telli_consumption', ['type' => 'aggregate']);
        $this->assertCount(0, $aggregates2, 'No aggregate should be created when values are identical');
        $this->assertStringNotContainsString('aggregate limit was reset', $output2);

        // Verify both current records have the same value.
        $recordsarray = array_values($records2);
        $this->assertEqualsWithDelta(
            (float)$recordsarray[0]->value,
            (float)$recordsarray[1]->value,
            0.000001,
            'Both current records should have identical values'
        );
    }

    /**
     * Data provider for error handling tests.
     *
     * @return array Test scenarios
     */
    public static function error_handling_provider(): array {
        return [
            'invalid_json' => [
                'mockdata' => '{invalid json',
                'expectedmessage' => 'Failed to decode usage data',
            ],
            'missing_remaining_field' => [
                'mockdata' => '{"limitInCent": 10000000}',
                'expectedmessage' => 'Invalid usage data structure',
            ],
            'missing_limit_field' => [
                'mockdata' => '{"remainingLimitInCent": 9996954.58}',
                'expectedmessage' => 'Invalid usage data structure',
            ],
        ];
    }

    /**
     * Test error handling for invalid API responses.
     *
     * @dataProvider error_handling_provider
     * @param string $mockdata Mock API response data
     * @param string $expectedmessage Expected error message
     */
    public function test_execute_handles_api_errors(string $mockdata, string $expectedmessage): void {
        global $DB;

        $this->resetAfterTest();
        $this->setup_config();
        $apiconnector = $this->getMockBuilder(\aitool_telli\local\apihandler::class)->onlyMethods(['get_usage_info'])->getMock();
        $apiconnector->expects($this->any())->method('get_usage_info')->willReturn($mockdata);
        \core\di::set(\aitool_telli\local\apihandler::class, $apiconnector);
        $output = $this->execute_task();

        $this->assertStringContainsString($expectedmessage, $output);
        $this->assertEmpty($DB->get_records('aitool_telli_consumption'));
    }

    /**
     * Test edge cases: zero and full consumption.
     */
    public function test_execute_handles_edge_cases(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setup_config();

        // Test zero consumption.
        $this->set_mock_data(10000000, 10000000);
        $this->execute_task();
        $record = $DB->get_record('aitool_telli_consumption', ['type' => 'current']);
        $this->assertEquals(0, $record->value);

        // Clear DB for second test.
        $DB->delete_records('aitool_telli_consumption');

        // Test full consumption.
        $this->set_mock_data(10000000, 0);
        $this->execute_task();
        $record = $DB->get_record('aitool_telli_consumption', ['type' => 'current']);
        $this->assertEquals(10000000, $record->value);
    }

    /**
     * Test cleanup of old consumption data based on retention period.
     *
     * @covers \aitool_telli\task\get_consumption::cleanup_old_data
     */
    public function test_cleanup_old_data(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setup_config();

        // Set retention period to 30 days.
        set_config('retentionperiod', 30 * DAYSECS, 'aitool_telli');

        // Create and freeze clock first.
        $currenttime = time();
        $clock = $this->mock_clock_with_frozen($currenttime);
        \core\di::set(\core\clock::class, $clock);

        // Create old records that should be deleted (older than 30 days).
        $oldrecord1 = new \stdClass();
        $oldrecord1->type = 'current';
        $oldrecord1->value = 1000;
        $oldrecord1->timecreated = $clock->time() - 35 * DAYSECS; // Clearly older than 30 days.
        $DB->insert_record('aitool_telli_consumption', $oldrecord1);

        $oldrecord2 = new \stdClass();
        $oldrecord2->type = 'aggregate';
        $oldrecord2->value = 2000;
        $oldrecord2->timecreated = $clock->time() - 60 * DAYSECS;
        $DB->insert_record('aitool_telli_consumption', $oldrecord2);

        // Create records that should be kept (newer than 30 days).
        $newrecord1 = new \stdClass();
        $newrecord1->type = 'current';
        $newrecord1->value = 3000;
        $newrecord1->timecreated = $clock->time() - 29 * DAYSECS;
        $DB->insert_record('aitool_telli_consumption', $newrecord1);

        $newrecord2 = new \stdClass();
        $newrecord2->type = 'current';
        $newrecord2->value = 4000;
        $newrecord2->timecreated = $clock->time() - 1 * DAYSECS;
        $DB->insert_record('aitool_telli_consumption', $newrecord2);

        // Verify we have 4 records before cleanup.
        $this->assertCount(4, $DB->get_records('aitool_telli_consumption'));

        // Execute task (which includes cleanup).
        $this->set_mock_data(10000000, 9995000);
        $output = $this->execute_task();

        // Verify old records were deleted (2 old records) and new ones kept (2 new records + 1 from task).
        $remainingrecords = $DB->get_records('aitool_telli_consumption');
        $this->assertCount(3, $remainingrecords);

        // Verify the correct records were kept.
        $this->assertNotEmpty($DB->get_record('aitool_telli_consumption', ['value' => 3000]));
        $this->assertNotEmpty($DB->get_record('aitool_telli_consumption', ['value' => 4000]));
        $this->assertNotEmpty($DB->get_record('aitool_telli_consumption', ['value' => 5000])); // From task execution.

        // Verify old records were deleted.
        $this->assertEmpty($DB->get_record('aitool_telli_consumption', ['value' => 1000]));
        $this->assertEmpty($DB->get_record('aitool_telli_consumption', ['value' => 2000]));

        // Verify cleanup message in output.
        $this->assertStringContainsString('Cleaned up 2 old consumption record(s)', $output);
    }

    /**
     * Test that cleanup is skipped when retention period is not set.
     *
     * @covers \aitool_telli\task\get_consumption::cleanup_old_data
     */
    public function test_cleanup_skipped_when_no_retention_period(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setup_config();

        // Do not set retention period (or set it to 0).
        set_config('retentionperiod', 0, 'aitool_telli');

        $currenttime = time();
        $clock = $this->mock_clock_with_frozen($currenttime);
        \core\di::set(\core\clock::class, $clock);

        // Create very old records.
        $oldrecord = new \stdClass();
        $oldrecord->type = 'current';
        $oldrecord->value = 1000;
        $oldrecord->timecreated = $clock->time() - 365 * DAYSECS;
        $DB->insert_record('aitool_telli_consumption', $oldrecord);

        $this->assertCount(1, $DB->get_records('aitool_telli_consumption'));

        // Execute task.
        $this->set_mock_data(10000000, 9995000);
        $output = $this->execute_task();

        // Verify old record was NOT deleted (cleanup skipped).
        $this->assertCount(2, $DB->get_records('aitool_telli_consumption'));
        $this->assertNotEmpty($DB->get_record('aitool_telli_consumption', ['value' => 1000]));

        // Verify no cleanup message in output.
        $this->assertStringNotContainsString('Cleaned up', $output);
    }

    /**
     * Test that cleanup happens at the end of task execution.
     *
     * @covers \aitool_telli\task\get_consumption::execute
     * @covers \aitool_telli\task\get_consumption::cleanup_old_data
     */
    public function test_cleanup_happens_after_consumption_storage(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setup_config();
        set_config('retentionperiod', 30 * DAYSECS, 'aitool_telli');

        $currenttime = time();
        $clock = $this->mock_clock_with_frozen($currenttime);
        \core\di::set(\core\clock::class, $clock);

        // Create an old record that should be deleted.
        $oldrecord = new \stdClass();
        $oldrecord->type = 'current';
        $oldrecord->value = 1000;
        $oldrecord->timecreated = $clock->time() - 31 * DAYSECS;
        $DB->insert_record('aitool_telli_consumption', $oldrecord);

        // Execute task.
        $this->set_mock_data(10000000, 9995000);
        $output = $this->execute_task();

        // Verify output contains consumption storage message before cleanup message.
        $consumptionpos = strpos($output, 'Stored current consumption');
        $cleanuppos = strpos($output, 'Cleaned up');

        $this->assertNotFalse($consumptionpos, 'Output should contain consumption storage message');
        $this->assertNotFalse($cleanuppos, 'Output should contain cleanup message');
        $this->assertLessThan($cleanuppos, $consumptionpos, 'Cleanup should happen after consumption storage');

        // Verify old record was deleted and new one was created.
        $records = $DB->get_records('aitool_telli_consumption');
        $this->assertCount(1, $records);
        $record = reset($records);
        $this->assertEquals(5000, $record->value);
    }
}

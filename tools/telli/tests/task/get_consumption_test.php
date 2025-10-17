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
        $apiconnector = $this->getMockBuilder(\aitool_telli\local\apihandler::class)->onlyMethods(['get_usage_info'])->getMock();
        $apiconnector->expects($this->any())->method('get_usage_info')->willReturn([json_encode([
            'limitInCent' => $limit,
            'remainingLimitInCent' => $remaining,
        ])]);

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
        $this->assertEquals(3045, $storedrecord->value); // 10000000 - 9996954.58 = 3045.42 → 3045.
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
        ];
    }

    /**
     * Test aggregate reset detection logic.
     *
     * @dataProvider reset_detection_provider
     * @param int $previous Previous consumption value
     * @param int $newconsumption New consumption value
     * @param bool $expectaggregate Whether a aggregate record should be created
     * @param int|null $expectedaggregatevalue Expected aggregate record value
     * @param int $expectedcurrent Expected current record value
     * @param string|null $expectedmessage Expected log message
     */
    public function test_execute_reset_detection(
        int $previous,
        int $newconsumption,
        bool $expectaggregate,
        ?int $expectedaggregatevalue,
        int $expectedcurrent,
        ?string $expectedmessage
    ): void {
        global $DB;

        $this->resetAfterTest();
        $this->setup_config();

        // Insert previous consumption record.
        $previousrecord = new \stdClass();
        $previousrecord->type = 'current';
        $previousrecord->value = $previous;
        $previousrecord->timecreated = time() - 3600;
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
            $this->assertEquals($expectedaggregatevalue, $aggregaterecord->value);
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
        $this->assertEquals($expectedcurrent, $currentrecord->value);
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
}

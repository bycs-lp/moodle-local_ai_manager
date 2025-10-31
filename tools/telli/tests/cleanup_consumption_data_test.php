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

namespace aitool_telli;

use aitool_telli\local\cleanup_consumption_data;

/**
 * Tests for the consumption data cleanup functionality.
 *
 * This tests the upgrade step that cleans up false positive aggregate records
 * caused by floating-point precision issues in consumption monitoring.
 *
 * @package    aitool_telli
 * @category   test
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \aitool_telli\local\cleanup_consumption_data
 */
final class cleanup_consumption_data_test extends \advanced_testcase {
    /**
     * Test cleanup with false positive aggregate records.
     */
    public function test_cleanup_removes_false_positives(): void {
        global $DB;

        $this->resetAfterTest();

        // Create test data with false positive aggregate.
        $time = time();

        // First current record.
        $record1 = new \stdClass();
        $record1->type = 'current';
        $record1->value = 3000.0;
        $record1->timecreated = $time - 200;
        $DB->insert_record('aitool_telli_consumption', $record1);

        // False positive aggregate (tiny decrease within epsilon).
        $falseaggregate = new \stdClass();
        $falseaggregate->type = 'aggregate';
        $falseaggregate->value = 3000.0;
        $falseaggregate->timecreated = $time - 100;
        $DB->insert_record('aitool_telli_consumption', $falseaggregate);

        // Second current record (tiny decrease).
        $record2 = new \stdClass();
        $record2->type = 'current';
        $record2->value = 2999.99;
        $record2->timecreated = $time - 100;
        $DB->insert_record('aitool_telli_consumption', $record2);

        // Third current record (normal increase).
        $record3 = new \stdClass();
        $record3->type = 'current';
        $record3->value = 3100.0;
        $record3->timecreated = $time;
        $DB->insert_record('aitool_telli_consumption', $record3);

        // Run cleanup.
        $cleanup = new cleanup_consumption_data(false, false);
        $success = $cleanup->execute();

        $this->assertTrue($success, 'Cleanup should complete successfully');

        // Debug: Check statistics.
        $stats = $cleanup->get_stats();

        // Verify results.
        $allrecords = $DB->get_records('aitool_telli_consumption', null, 'timecreated ASC, id ASC');

        // Should have 3 current records, 0 aggregate records.
        $currentcount = 0;
        $aggregatecount = 0;
        foreach ($allrecords as $record) {
            if ($record->type === 'current') {
                $currentcount++;
            } else if ($record->type === 'aggregate') {
                $aggregatecount++;
            }
        }

        $this->assertEquals(3, $currentcount, 'Should have 3 current records');
        $this->assertEquals(
            0,
            $aggregatecount,
            'False positive aggregate should be removed. Stats: ' . json_encode($stats)
        );
    }

    /**
     * Test cleanup preserves legitimate aggregate records.
     */
    public function test_cleanup_preserves_legitimate_resets(): void {
        global $DB;

        $this->resetAfterTest();

        $time = time();

        // First current record.
        $record1 = new \stdClass();
        $record1->type = 'current';
        $record1->value = 5000.0;
        $record1->timecreated = $time - 300;
        $DB->insert_record('aitool_telli_consumption', $record1);

        // Second current record (significant decrease = reset).
        $record2 = new \stdClass();
        $record2->type = 'current';
        $record2->value = 1000.0;
        $record2->timecreated = $time - 200;
        $DB->insert_record('aitool_telli_consumption', $record2);

        // Third current record (normal increase).
        $record3 = new \stdClass();
        $record3->type = 'current';
        $record3->value = 1500.0;
        $record3->timecreated = $time - 100;
        $DB->insert_record('aitool_telli_consumption', $record3);

        // Run cleanup.
        $cleanup = new cleanup_consumption_data(false, false);
        $success = $cleanup->execute();

        $this->assertTrue($success);

        // Verify aggregate was created.
        $aggregaterecords = $DB->get_records('aitool_telli_consumption', ['type' => 'aggregate']);
        $this->assertCount(1, $aggregaterecords, 'Should have 1 legitimate aggregate record');

        $aggregate = reset($aggregaterecords);
        $this->assertEqualsWithDelta(5000.0, (float)$aggregate->value, 0.01);
        $this->assertEquals($time - 300, $aggregate->timecreated, 'Aggregate should have timestamp of pre-reset record');

        // Verify current records.
        $currentrecords = $DB->get_records('aitool_telli_consumption', ['type' => 'current'], 'timecreated ASC');
        $this->assertCount(3, $currentrecords);
    }

    /**
     * Test cleanup with multiple resets.
     */
    public function test_cleanup_multiple_resets(): void {
        global $DB;

        $this->resetAfterTest();

        $time = time();

        // Create pattern: high → low (reset 1) → high → low (reset 2).
        $testdata = [
            ['value' => 5000.0, 'time' => $time - 400],
            ['value' => 1000.0, 'time' => $time - 300], // Reset 1.
            ['value' => 2000.0, 'time' => $time - 200],
            ['value' => 500.0, 'time' => $time - 100], // Reset 2.
            ['value' => 800.0, 'time' => $time],
        ];

        foreach ($testdata as $data) {
            $record = new \stdClass();
            $record->type = 'current';
            $record->value = $data['value'];
            $record->timecreated = $data['time'];
            $DB->insert_record('aitool_telli_consumption', $record);
        }

        // Run cleanup.
        $cleanup = new cleanup_consumption_data(false, false);
        $success = $cleanup->execute();

        $this->assertTrue($success);

        // Verify 2 aggregate records created.
        $aggregaterecords = $DB->get_records('aitool_telli_consumption', ['type' => 'aggregate'], 'timecreated ASC');
        $this->assertCount(2, $aggregaterecords, 'Should have 2 aggregate records for 2 resets');

        $aggregates = array_values($aggregaterecords);
        $this->assertEqualsWithDelta(5000.0, (float)$aggregates[0]->value, 0.01, 'First aggregate should be 5000');
        $this->assertEqualsWithDelta(2000.0, (float)$aggregates[1]->value, 0.01, 'Second aggregate should be 2000');

        // Verify all current records preserved.
        $currentrecords = $DB->get_records('aitool_telli_consumption', ['type' => 'current']);
        $this->assertCount(5, $currentrecords);
    }

    /**
     * Test cleanup with no changes needed (all data already correct).
     */
    public function test_cleanup_no_changes_needed(): void {
        global $DB;

        $this->resetAfterTest();

        $time = time();

        // Create correct data (only increasing values, no resets).
        $testdata = [
            ['value' => 1000.0, 'time' => $time - 300],
            ['value' => 1500.0, 'time' => $time - 200],
            ['value' => 2000.0, 'time' => $time - 100],
            ['value' => 2500.0, 'time' => $time],
        ];

        foreach ($testdata as $data) {
            $record = new \stdClass();
            $record->type = 'current';
            $record->value = $data['value'];
            $record->timecreated = $data['time'];
            $DB->insert_record('aitool_telli_consumption', $record);
        }

        // Run cleanup.
        $cleanup = new cleanup_consumption_data(false, false);
        $success = $cleanup->execute();

        $this->assertTrue($success);

        // Verify no aggregates created.
        $aggregaterecords = $DB->get_records('aitool_telli_consumption', ['type' => 'aggregate']);
        $this->assertCount(0, $aggregaterecords, 'Should have no aggregate records');

        // Verify all current records preserved.
        $currentrecords = $DB->get_records('aitool_telli_consumption', ['type' => 'current'], 'timecreated ASC');
        $this->assertCount(4, $currentrecords);

        // Verify values maintained.
        $values = array_map(fn($r) => (float)$r->value, array_values($currentrecords));
        $this->assertEquals([1000.0, 1500.0, 2000.0, 2500.0], $values);
    }

    /**
     * Test cleanup preserves timestamps.
     */
    public function test_cleanup_preserves_timestamps(): void {
        global $DB;

        $this->resetAfterTest();

        $time1 = 1609459200;
        $time2 = 1609545600;
        $time3 = 1609632000;

        $record1 = new \stdClass();
        $record1->type = 'current';
        $record1->value = 3000.0;
        $record1->timecreated = $time1;
        $DB->insert_record('aitool_telli_consumption', $record1);

        $record2 = new \stdClass();
        $record2->type = 'current';
        $record2->value = 3500.0;
        $record2->timecreated = $time2;
        $DB->insert_record('aitool_telli_consumption', $record2);

        $record3 = new \stdClass();
        $record3->type = 'current';
        $record3->value = 4000.0;
        $record3->timecreated = $time3;
        $DB->insert_record('aitool_telli_consumption', $record3);

        // Run cleanup.
        $cleanup = new cleanup_consumption_data(false, false);
        $success = $cleanup->execute();

        $this->assertTrue($success);

        // Verify timestamps preserved.
        $records = $DB->get_records('aitool_telli_consumption', ['type' => 'current'], 'timecreated ASC');
        $timestamps = array_map(fn($r) => $r->timecreated, array_values($records));
        $this->assertEquals([$time1, $time2, $time3], $timestamps, 'Original timestamps should be preserved');
    }

    /**
     * Test cleanup with epsilon boundary cases.
     */
    public function test_cleanup_epsilon_boundary_cases(): void {
        global $DB;

        $this->resetAfterTest();

        $time = time();

        // Test exact epsilon boundary (0.01 difference).
        $record1 = new \stdClass();
        $record1->type = 'current';
        $record1->value = 3000.0;
        $record1->timecreated = $time - 300;
        $DB->insert_record('aitool_telli_consumption', $record1);

        // Exactly at epsilon boundary (should NOT trigger reset).
        $record2 = new \stdClass();
        $record2->type = 'current';
        $record2->value = 2999.99;
        $record2->timecreated = $time - 200;
        $DB->insert_record('aitool_telli_consumption', $record2);

        // Just beyond epsilon (should trigger reset).
        $record3 = new \stdClass();
        $record3->type = 'current';
        $record3->value = 2999.0;
        $record3->timecreated = $time - 100;
        $DB->insert_record('aitool_telli_consumption', $record3);

        // Run cleanup.
        $cleanup = new cleanup_consumption_data(false, false);
        $success = $cleanup->execute();

        $this->assertTrue($success);

        // Should have exactly 1 aggregate (for the 2999.99 → 2999.0 transition).
        $aggregaterecords = $DB->get_records('aitool_telli_consumption', ['type' => 'aggregate']);
        $this->assertCount(1, $aggregaterecords, 'Should have 1 aggregate for beyond-epsilon reset');

        $aggregate = reset($aggregaterecords);
        $this->assertEqualsWithDelta(2999.99, (float)$aggregate->value, 0.01);
    }

    /**
     * Test cleanup handles empty database.
     */
    public function test_cleanup_empty_database(): void {
        global $DB;

        $this->resetAfterTest();

        // Ensure table is empty.
        $DB->delete_records('aitool_telli_consumption');

        // Run cleanup.
        $cleanup = new cleanup_consumption_data(false, false);
        $success = $cleanup->execute();

        $this->assertTrue($success, 'Cleanup should handle empty database gracefully');

        $records = $DB->get_records('aitool_telli_consumption');
        $this->assertEmpty($records);
    }

    /**
     * Test cleanup with high precision float values.
     */
    public function test_cleanup_high_precision_floats(): void {
        global $DB;

        $this->resetAfterTest();

        $time = time();

        // Use real-world precision values (6 decimal places).
        $record1 = new \stdClass();
        $record1->type = 'current';
        $record1->value = 3045.418385;
        $record1->timecreated = $time - 200;
        $DB->insert_record('aitool_telli_consumption', $record1);

        // Identical value (should not trigger aggregate).
        $record2 = new \stdClass();
        $record2->type = 'current';
        $record2->value = 3045.418385;
        $record2->timecreated = $time - 100;
        $DB->insert_record('aitool_telli_consumption', $record2);

        // Slightly higher.
        $record3 = new \stdClass();
        $record3->type = 'current';
        $record3->value = 3045.428385;
        $record3->timecreated = $time;
        $DB->insert_record('aitool_telli_consumption', $record3);

        // Run cleanup.
        $cleanup = new cleanup_consumption_data(false, false);
        $success = $cleanup->execute();

        $this->assertTrue($success);

        // No aggregates should be created.
        $aggregaterecords = $DB->get_records('aitool_telli_consumption', ['type' => 'aggregate']);
        $this->assertCount(0, $aggregaterecords);

        // Verify precision preserved.
        $currentrecords = $DB->get_records('aitool_telli_consumption', ['type' => 'current'], 'timecreated ASC');
        $values = array_values($currentrecords);
        $this->assertEqualsWithDelta(3045.418385, (float)$values[0]->value, 0.000001);
        $this->assertEqualsWithDelta(3045.418385, (float)$values[1]->value, 0.000001);
        $this->assertEqualsWithDelta(3045.428385, (float)$values[2]->value, 0.000001);
    }
}

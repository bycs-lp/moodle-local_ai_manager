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

namespace aipurpose_stt;

use advanced_testcase;

/**
 * Tests for purpose class.
 *
 * @package    aipurpose_stt
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \aipurpose_stt\purpose
 */
class purpose_test extends advanced_testcase {

    /**
     * Test format_output returns clean text.
     *
     * @return void
     */
    public function test_format_output(): void {
        $this->resetAfterTest();

        $purpose = new purpose();

        $input = "Hello, this is a test transcription.\nWith multiple lines.";
        $output = $purpose->format_output($input);

        $this->assertIsString($output);
        $this->assertStringContainsString('Hello', $output);
    }

    /**
     * Test temperature validation - valid range.
     *
     * @return void
     */
    public function test_temperature_validation_valid(): void {
        $this->resetAfterTest();

        $purpose = new purpose();

        // Valid temperature.
        $options = ['temperature' => 0.5];
        $result = $purpose->get_additional_request_options($options);
        $this->assertEquals(0.5, $result['temperature']);
    }

    /**
     * Test temperature validation - invalid too high.
     *
     * @return void
     */
    public function test_temperature_validation_too_high(): void {
        $this->resetAfterTest();

        $purpose = new purpose();

        // Invalid temperature - too high.
        $this->expectException(\invalid_parameter_exception::class);
        $options = ['temperature' => 1.5];
        $purpose->get_additional_request_options($options);
    }

    /**
     * Test temperature validation - invalid too low.
     *
     * @return void
     */
    public function test_temperature_validation_too_low(): void {
        $this->resetAfterTest();

        $purpose = new purpose();

        // Invalid temperature - too low.
        $this->expectException(\invalid_parameter_exception::class);
        $options = ['temperature' => -0.5];
        $purpose->get_additional_request_options($options);
    }

    /**
     * Test default values are set.
     *
     * @return void
     */
    public function test_default_values(): void {
        $this->resetAfterTest();

        $purpose = new purpose();

        $options = [];
        $result = $purpose->get_additional_request_options($options);

        $this->assertEquals('text', $result['response_format']);
        $this->assertEquals(0.0, $result['temperature']);
    }

    /**
     * Test get_plugin_name returns correct name.
     *
     * @return void
     */
    public function test_get_plugin_name(): void {
        $this->resetAfterTest();

        $purpose = new purpose();
        $pluginname = $purpose->get_plugin_name();

        $this->assertEquals('stt', $pluginname);
    }
}

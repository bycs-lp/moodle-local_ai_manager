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

namespace aitool_openaistt;

use advanced_testcase;

/**
 * PHPUnit tests for instance class.
 *
 * @package    aitool_openaistt
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \aitool_openaistt\instance
 */
class instance_test extends advanced_testcase {

    /**
     * Test extend_store_formdata sets correct endpoint.
     */
    public function test_extend_store_formdata_sets_endpoint(): void {
        $this->resetAfterTest();

        $instance = new instance(0);
        $data = new \stdClass();

        // Call the method.
        $instance->extend_store_formdata($data);

        // Verify endpoint is set.
        $this->assertObjectHasProperty('endpoint', $data);
        $this->assertEquals('https://api.openai.com/v1/audio/transcriptions', $data->endpoint);
    }

    /**
     * Test extend_form_definition does not add custom fields.
     */
    public function test_extend_form_definition_no_custom_fields(): void {
        $this->resetAfterTest();

        $instance = new instance(0);
        $mform = $this->createMock(\MoodleQuickForm::class);

        // Ensure no elements are added.
        $mform->expects($this->never())->method('addElement');

        $instance->extend_form_definition($mform);
    }
}

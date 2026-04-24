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

/**
 * Tests for the external_description → JSON-Schema converter (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent;

use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Tests for {@see ws_schema_converter}.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_manager\agent\ws_schema_converter
 */
final class ws_schema_converter_test extends \advanced_testcase {

    /**
     * PARAM_* types are mapped to correct JSON-Schema types.
     */
    public function test_param_type_mapping(): void {
        $this->assertSame('integer', ws_schema_converter::param_type_to_json_type(PARAM_INT));
        $this->assertSame('number', ws_schema_converter::param_type_to_json_type(PARAM_FLOAT));
        $this->assertSame('boolean', ws_schema_converter::param_type_to_json_type(PARAM_BOOL));
        $this->assertSame('string', ws_schema_converter::param_type_to_json_type(PARAM_TEXT));
        $this->assertSame('string', ws_schema_converter::param_type_to_json_type(PARAM_RAW));
    }

    /**
     * A simple scalar external_value converts to a typed schema.
     */
    public function test_convert_external_value(): void {
        $desc = new external_value(PARAM_INT, 'The course ID', VALUE_REQUIRED, null, NULL_NOT_ALLOWED);
        $schema = ws_schema_converter::convert($desc);
        $this->assertSame('integer', $schema['type']);
        $this->assertSame('The course ID', $schema['description']);
    }

    /**
     * A single_structure becomes an object with properties + required array.
     */
    public function test_convert_single_structure(): void {
        $params = new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'course id'),
            'name' => new external_value(PARAM_TEXT, 'name', VALUE_OPTIONAL),
        ]);
        $schema = ws_schema_converter::convert($params);

        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('courseid', $schema['properties']);
        $this->assertArrayHasKey('name', $schema['properties']);
        $this->assertContains('courseid', $schema['required']);
        $this->assertNotContains('name', $schema['required'] ?? []);
        $this->assertFalse($schema['additionalProperties']);
    }

    /**
     * Nullable fields are represented as union-types.
     */
    public function test_convert_nullable_value(): void {
        $desc = new external_value(PARAM_INT, 'optional id', VALUE_OPTIONAL, null, NULL_ALLOWED);
        $schema = ws_schema_converter::convert($desc);
        $this->assertSame(['integer', 'null'], $schema['type']);
    }

    /**
     * Multiple-structures become JSON-Schema arrays with an items definition.
     */
    public function test_convert_multiple_structure(): void {
        $desc = new external_multiple_structure(
            new external_value(PARAM_INT, 'a value', VALUE_REQUIRED, null, NULL_NOT_ALLOWED)
        );
        $schema = ws_schema_converter::convert($desc);
        $this->assertSame('array', $schema['type']);
        $this->assertArrayHasKey('items', $schema);
        $this->assertSame('integer', $schema['items']['type']);
    }
}

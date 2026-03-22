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

/**
 * Unit tests for the tenant data object.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class tenant_test extends \advanced_testcase {

    /**
     * @dataProvider valid_identifier_provider
     * @covers \local_ai_manager\local\tenant::__construct
     */
    public function test_validation_accepts_valid_identifiers(string $identifier): void {
        $tenant = new \local_ai_manager\local\tenant($identifier);
        $this->assertSame($identifier, $tenant->get_identifier());
    }

    /**
     * Data provider with valid identifiers (letters, numbers, hyphens and spaces allowed).
     *
     * @return array
     */
    public static function valid_identifier_provider(): array {
        return [
            ['default'],
            ['tenant-1'],
            ['Maths Department'],
            ['School-123 Munich'],
            ['123'],
            ['a b c'],
            ['underscore_university'],
        ];
    }

    /**
     * @dataProvider invalid_identifier_provider
     * @covers \local_ai_manager\local\tenant::__construct
     */
    public function test_validation_rejects_invalid_identifier(string $identifier): void {
        $this->expectException(\invalid_parameter_exception::class);
        new \local_ai_manager\local\tenant($identifier);
    }

    /**
     * Data provider with invalid identifiers containing characters not allowed by the regex.
     *
     * @return array
     */
    public static function invalid_identifier_provider(): array {
        return [
            ['name!'],
            ['name@domain'],
            ['name#hash'],
            ['percent%'],
            ['newline\n'],
            ['unicode-ä'],
            ['/slashes/'],
            ['comma,comma'],
            ['HTML-Tag<br />'],
            [' Whitespace University'],
            ['Whitespace School ']
        ];
    }
}

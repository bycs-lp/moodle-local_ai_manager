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

namespace local_ai_manager\local;

/**
 * Unit tests for the tenant data object.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Johannes Funk
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class tenant_test extends \advanced_testcase {
    /**
     * Tests the validation of identifier string in the tenant constructor.
     *
     * @dataProvider identifier_provider
     * @covers \local_ai_manager\local\tenant::__construct
     */
    public function test_validation($name, $valid): void {
        if ($valid) {
            $tenant = new \local_ai_manager\local\tenant($name);
            $this->assertSame($name, $tenant->get_identifier());
        } else {
            $this->expectException(\invalid_parameter_exception::class);
            new \local_ai_manager\local\tenant($name);
        }
    }

    /**
     * Data provider.
     *
     * @return array
     */
    public static function identifier_provider(): array {
        return [
            'default_identifier' => [
                'name' => 'default',
                'valid' => true,
            ],
            'identifier_with_hyphen' => [
                'name' => 'tenant-1',
                'valid' => true,
            ],
            'identifier_with_space' => [
                'name' => 'Maths Department',
                'valid' => true,
            ],
            'identifier_with_hypen_and_numbers' => [
                'name' => 'School-123 Munich',
                'valid' => true,
            ],
            'identifier_only_numeric' => [
                'name' => '123',
                'valid' => true,
            ],
            'identifier_with_multiple_spaces' => [
                'name' => 'a b c',
                'valid' => true,
            ],
            'identifier_with_underscore' => [
                'name' => 'underscore_university',
                'valid' => true,
            ],
            'identifier_with_exclamation_mark' => [
                'name' => 'name!',
                'valid' => false,
            ],
            'identifier_with_at_symbol' => [
                'name' => 'name@domain',
                'valid' => false,
            ],
            'identifier_with_hashtag' => [
                'name' => 'name#hash',
                'valid' => false,
            ],
            'identifier_with_percent' => [
                'name' => 'percent%',
                'valid' => false,
            ],
            'identifier_with_backslash' => [
                'name' => 'newline\n',
                'valid' => false,
            ],
            'identifier_with_umlaut' => [
                'name' => 'unicode-ä',
                'valid' => false,
            ],
            'identifier_with_slashes' => [
                'name' => '/slashes/',
                'valid' => false,
            ],
            'identifier_with_comma' => [
                'name' => 'comma,comma',
                'valid' => false,
            ],
            'identifier_with_HTML' => [
                'name' => 'HTML-Tag<br />',
                'valid' => false,
            ],
            'identifier_with_leading_whitespace' => [
                'name' => ' Whitespace University',
                'valid' => false,
            ],
            'identifier_with_trailing_whitespace' => [
                'name' => 'Whitespace School ',
                'valid' => false,
            ],
        ];
    }
}

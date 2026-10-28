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

use local_ai_manager\tests\array_option_purpose;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for the sanitising of request options in local_ai_manager\request_options.
 *
 * @package   local_ai_manager
 * @copyright 2026 ISB Bayern
 * @author    Peter Mayer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(request_options::class)]
final class request_options_test extends \advanced_testcase {
    /**
     * Data provider for test_sanitize_options_array_option_violation.
     *
     * @return array datasets with the invalid value of the array option
     */
    public static function sanitize_options_array_option_violation_provider(): array {
        return [
            'no_array' => ['items' => 'no array'],
            'rejected_by_purpose' => ['items' => ['valid', 'invalid']],
        ];
    }

    /**
     * Tests that array options which are no array or are rejected by the purpose validation lead to an exception.
     *
     * @param mixed $items the invalid value of the array option
     */
    #[DataProvider('sanitize_options_array_option_violation_provider')]
    public function test_sanitize_options_array_option_violation(mixed $items): void {
        $requestoptions = new request_options(
            new array_option_purpose(),
            \context_system::instance(),
            'local_ai_manager',
            ['items' => $items]
        );
        $this->expectException(\coding_exception::class);
        $requestoptions->sanitize_options();
    }

    /**
     * Tests that the content of array options reaches the connectors unchanged.
     *
     * Array options like conversation contexts legitimately contain code, markup and XML which must not be stripped.
     */
    public function test_sanitize_options_array_option_verification(): void {
        $items = [
            'What does if (a<b && c>d) do? List<String> list = new ArrayList<>();',
            ['nested' => '<div class="x">Hello</div>'],
            '<quiz><question type="multichoice"><name><text>Q1</text></name></question></quiz>',
        ];
        $requestoptions = new request_options(
            new array_option_purpose(),
            \context_system::instance(),
            'local_ai_manager',
            ['items' => $items]
        );
        $requestoptions->sanitize_options();
        $this->assertSame($items, $requestoptions->get_options()['items']);
    }
}

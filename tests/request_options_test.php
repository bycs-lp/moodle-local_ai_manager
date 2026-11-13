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
     * Data provider for test_construct_array_option_violation.
     *
     * @return array datasets with the invalid value of the array option
     */
    public static function construct_array_option_violation_provider(): array {
        return [
            'no_array' => ['items' => 'no array'],
            'rejected_by_purpose' => ['items' => ['valid', 'invalid']],
        ];
    }

    /**
     * Tests that submitted array options which are no array or are rejected by the purpose validation lead to an exception.
     *
     * @param mixed $items the invalid value of the array option
     */
    #[DataProvider('construct_array_option_violation_provider')]
    public function test_construct_array_option_violation(mixed $items): void {
        $this->expectException(\coding_exception::class);
        new request_options(
            new array_option_purpose(),
            \context_system::instance(),
            'local_ai_manager',
            ['items' => $items]
        );
    }

    /**
     * Tests that options which are not defined by the purpose lead to an exception.
     */
    public function test_construct_undefined_option_violation(): void {
        $this->expectException(\coding_exception::class);
        new request_options(
            new array_option_purpose(),
            \context_system::instance(),
            'local_ai_manager',
            ['undefined' => 'value']
        );
    }

    /**
     * Tests that the purpose validation receives the sanitized options.
     */
    public function test_construct_purpose_validation_receives_sanitized_options(): void {
        $purpose = new array_option_purpose();
        new request_options(
            $purpose,
            \context_system::instance(),
            'local_ai_manager',
            ['itemid' => '12abc', 'items' => ['valid']]
        );
        $this->assertSame(['itemid' => 12, 'items' => ['valid']], $purpose->validatedoptions);
    }

    /**
     * Tests that the content of array options reaches the connectors unchanged.
     *
     * Array options like conversation contexts legitimately contain code, markup and XML which must not be stripped.
     */
    public function test_construct_array_option_verification(): void {
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
        $this->assertSame($items, $requestoptions->get_options()['items']);
    }

    /**
     * Data provider for test_construct_sanitizes_sourceids.
     *
     * @return array Datasets with the submitted sourceids and the expected sanitized value.
     */
    public static function construct_sanitizes_sourceids_provider(): array {
        return [
            'valid_sequence' => ['sourceids' => '1,2,3', 'expected' => '1,2,3'],
            'single_id' => ['sourceids' => '42', 'expected' => '42'],
            'whitespace_is_removed' => ['sourceids' => '1, 2 ,3', 'expected' => '1,2,3'],
            'sql_injection_is_stripped' => ['sourceids' => '1); DROP TABLE mdl_user; --', 'expected' => '1'],
            'html_is_stripped' => ['sourceids' => '<script>alert(1)</script>', 'expected' => '1'],
            'negative_and_decimal_numbers_are_stripped' => ['sourceids' => '-1,2.5', 'expected' => '1,25'],
            'letters_only_result_in_empty_string' => ['sourceids' => 'abc', 'expected' => ''],
        ];
    }

    /**
     * Test that the submitted sourceids are cleaned as sequence of ids when creating the request options.
     *
     * @param string $sourceids The submitted sourceids option.
     * @param string $expected The expected sanitized sourceids option.
     */
    #[DataProvider('construct_sanitizes_sourceids_provider')]
    public function test_construct_sanitizes_sourceids(string $sourceids, string $expected): void {
        $requestoptions = new request_options(
            new base_purpose(),
            \core\context\system::instance(),
            'block_ai_chat',
            ['sourceids' => $sourceids]
        );

        $this->assertSame($expected, $requestoptions->get_options()['sourceids']);
    }

    /**
     * Data provider for test_construct_retrieval_option_validation.
     *
     * @return array Datasets with the submitted options and whether they are valid.
     */
    public static function construct_retrieval_option_validation_provider(): array {
        return [
            'minsimilarity_lower_bound' => ['options' => ['minsimilarity' => -1], 'valid' => true],
            'minsimilarity_upper_bound' => ['options' => ['minsimilarity' => 1], 'valid' => true],
            'minsimilarity_zero' => ['options' => ['minsimilarity' => 0], 'valid' => true],
            'minsimilarity_below' => ['options' => ['minsimilarity' => -1.01], 'valid' => false],
            'minsimilarity_above' => ['options' => ['minsimilarity' => 1.5], 'valid' => false],
            'maxretrievedchars_positive' => ['options' => ['maxretrievedchars' => 50000], 'valid' => true],
            'maxretrievedchars_zero' => ['options' => ['maxretrievedchars' => 0], 'valid' => false],
            'maxretrievedchars_negative' => ['options' => ['maxretrievedchars' => -10], 'valid' => false],
        ];
    }

    /**
     * Test that the general retrieval options are validated against their domain for every purpose.
     *
     * @param array $options The submitted options.
     * @param bool $valid Whether the options are valid.
     */
    #[DataProvider('construct_retrieval_option_validation_provider')]
    public function test_construct_retrieval_option_validation(array $options, bool $valid): void {
        if (!$valid) {
            $this->expectException(\coding_exception::class);
        }
        // A purpose overriding validate_purpose_options must not be able to skip the validation of the general options.
        $requestoptions = new request_options(
            new array_option_purpose(),
            \core\context\system::instance(),
            'block_ai_chat',
            $options
        );

        $this->assertEquals($options, array_intersect_key($requestoptions->get_options(), $options));
    }
}
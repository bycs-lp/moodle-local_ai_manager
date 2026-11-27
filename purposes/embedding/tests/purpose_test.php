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

namespace aipurpose_embedding;

/**
 * Tests for the embedding purpose.
 *
 * @package    aipurpose_embedding
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(purpose::class)]
final class purpose_test extends \basic_testcase {
    /**
     * Data provider for test_validate_purpose_options.
     *
     * @return array Datasets with the options and whether they are valid.
     */
    public static function validate_purpose_options_provider(): array {
        return [
            'no_prompttexts' => ['options' => [], 'valid' => true],
            'list_of_texts' => ['options' => [purpose::OPTION_PROMPTTEXTS => ['first', 'second']], 'valid' => true],
            'empty_list' => ['options' => [purpose::OPTION_PROMPTTEXTS => []], 'valid' => false],
            'no_list' => ['options' => [purpose::OPTION_PROMPTTEXTS => ['a' => 'first']], 'valid' => false],
            'not_a_string' => ['options' => [purpose::OPTION_PROMPTTEXTS => ['first', 2]], 'valid' => false],
            'nested_array' => ['options' => [purpose::OPTION_PROMPTTEXTS => [['first']]], 'valid' => false],
            'empty_text' => ['options' => [purpose::OPTION_PROMPTTEXTS => ['first', '  ']], 'valid' => false],
        ];
    }

    /**
     * Test the validation of the option with the texts to embed.
     *
     * @param array $options The submitted options.
     * @param bool $valid Whether the options are valid.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('validate_purpose_options_provider')]
    public function test_validate_purpose_options(array $options, bool $valid): void {
        if (!$valid) {
            $this->expectException(\coding_exception::class);
        }
        (new purpose())->validate_purpose_options($options);
        $this->addToAssertionCount(1);
    }
}



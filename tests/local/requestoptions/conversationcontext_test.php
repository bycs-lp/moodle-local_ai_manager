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

namespace local_ai_manager\local\requestoptions;

use local_ai_manager\base_purpose;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for local_ai_manager\local\requestoptions\conversationcontext.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(conversationcontext::class)]
final class conversationcontext_test extends \basic_testcase {
    /**
     * Tests the option definition.
     */
    public function test_get_option_definition(): void {
        $this->assertSame(
            ['conversationcontext' => base_purpose::PARAM_ARRAY],
            (new conversationcontext())->get_option_definition()
        );
    }

    /**
     * Data provider for test_validate_violation.
     *
     * @return array datasets with the invalid conversation context
     */
    public static function validate_violation_provider(): array {
        return [
            'entry_no_array' => ['conversationcontext' => ['no array']],
            'missing_sender' => ['conversationcontext' => [['message' => 'Hello']]],
            'invalid_sender' => ['conversationcontext' => [['sender' => 'admin', 'message' => 'Hello']]],
            'missing_message' => ['conversationcontext' => [['sender' => 'user']]],
            'nested_message' => ['conversationcontext' => [['sender' => 'user', 'message' => ['deep' => 'Hello']]]],
        ];
    }

    /**
     * Tests that a conversation context without the structure expected by the connectors is rejected.
     *
     * @param array $conversationcontext the invalid conversation context
     */
    #[DataProvider('validate_violation_provider')]
    public function test_validate_violation(array $conversationcontext): void {
        $this->expectException(\coding_exception::class);
        (new conversationcontext())->validate($conversationcontext);
    }

    /**
     * Tests that a valid conversation context including code and markup is accepted.
     */
    public function test_validate_verification(): void {
        $this->expectNotToPerformAssertions();
        (new conversationcontext())->validate([
            ['sender' => 'system', 'message' => 'Write it as <pre class="language-xxx"><code>...</code></pre>.'],
            ['sender' => 'user', 'message' => 'List<String> list = new ArrayList<>();'],
            ['sender' => 'ai', 'message' => '<div class="x">Hello</div>'],
        ]);
    }
}

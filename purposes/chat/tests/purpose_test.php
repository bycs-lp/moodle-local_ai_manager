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

namespace aipurpose_chat;

/**
 * Unit tests for the chat purpose.
 *
 * @package    aipurpose_chat
 * @copyright  2026 ISB Bayern
 * @author     Thomas Schönlein
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(purpose::class)]
final class purpose_test extends \advanced_testcase {
    /**
     * Tests that the formatting system message is prepended and any existing conversation context is preserved after it.
     */
    public function test_get_additional_request_options_prepends_system_message(): void {
        $this->resetAfterTest();

        $purpose = new purpose();
        $conversationcontext = $purpose->get_additional_request_options([]);
        $defaultprompt = purpose::get_default_chatsystemprompt();
        $context = $conversationcontext['conversationcontext'];

        $this->assertCount(1, $context);
        $this->assertEquals('system', $context[0]['sender']);
        $this->assertEquals($defaultprompt, $context[0]['message']);

        $existing = ['sender' => 'user', 'message' => 'Custom User-Prompt'];
        $result = $purpose->get_additional_request_options([
                'conversationcontext' => [$existing],
        ]);
        $context = $result['conversationcontext'];
        $this->assertCount(2, $context);
        $this->assertEquals('system', $context[0]['sender']);
        $this->assertEquals($defaultprompt, $context[0]['message']);
        $this->assertEquals($existing, $context[1]);
    }

    /**
     * Tests that a configured chatsystemprompt setting overrides the default formatting prompt.
     */
    public function test_get_additional_request_options_uses_configured_prompt(): void {
        $this->resetAfterTest();
        $customprompt = 'Custom System Prompt';
        set_config('chatsystemprompt', $customprompt, 'aipurpose_chat');
        $purpose = new purpose();
        $conversationcontext = $purpose->get_additional_request_options([]);
        $context = $conversationcontext['conversationcontext'];
        $this->assertEquals($customprompt, $context[0]['message']);
    }

    /**
     * Tests that the conversation context is validated by the conversation context validator.
     */
    public function test_validate_purpose_options_violation(): void {
        $this->expectException(\coding_exception::class);
        (new purpose())->validate_purpose_options(['conversationcontext' => [['sender' => 'admin', 'message' => 'Hello']]]);
    }

    /**
     * Tests that a valid conversation context including code and markup passes the sanitising unchanged.
     */
    public function test_validate_purpose_options_verification(): void {
        $conversationcontext = [
            ['sender' => 'user', 'message' => 'What does if (a<b && c>d) do? List<String> list = new ArrayList<>();'],
            ['sender' => 'ai', 'message' => '<div class="x">Hello</div>'],
        ];
        $requestoptions = new \local_ai_manager\request_options(
            new purpose(),
            \context_system::instance(),
            'block_ai_chat',
            ['conversationcontext' => $conversationcontext]
        );
        $requestoptions->sanitize_options();
        // The chat purpose prepends its system message, the submitted entries have to stay untouched.
        $this->assertSame($conversationcontext, array_slice($requestoptions->get_options()['conversationcontext'], 1));
    }
}

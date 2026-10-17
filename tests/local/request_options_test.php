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

use local_ai_manager\request_options;

/**
 * Tests for the sanitising of array options in local_ai_manager\request_options.
 *
 * @package   local_ai_manager
 * @copyright 2026 ISB Bayern
 * @author    Peter Mayer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class request_options_test extends \advanced_testcase {
    #[\PHPUnit\Framework\Attributes\Group('baseline')]
    /**
     * Tests that markup inside array typed options is stripped and that non-array values are rejected.
     *
     * @covers \local_ai_manager\request_options::sanitize_options
     */
    public function test_sanitize_options_array_option_violation(): void {
        $this->resetAfterTest();
        $requestoptions = new request_options(
            new \aipurpose_chat\purpose(),
            \context_system::instance(),
            'block_ai_chat',
            ['conversationcontext' => [
                ['sender' => 'user', 'message' => '<img src=x onerror=alert(1)>Hello'],
                ['sender' => 'ai', 'nested' => ['deep' => '<b>bold</b>']],
            ]]
        );
        $requestoptions->sanitize_options();
        $conversationcontext = $requestoptions->get_options()['conversationcontext'];
        $this->assertStringNotContainsString('<', json_encode($conversationcontext));
        $this->assertEquals('Hello', $conversationcontext[1]['message']);
        $this->assertEquals('bold', $conversationcontext[2]['nested']['deep']);

        $requestoptions->set_options(['conversationcontext' => 'no array']);
        $this->expectException(\coding_exception::class);
        $requestoptions->sanitize_options();
    }

    #[\PHPUnit\Framework\Attributes\Group('baseline')]
    /**
     * Tests that legitimate array options pass the sanitising unchanged.
     *
     * @covers \local_ai_manager\request_options::sanitize_options
     */
    public function test_sanitize_options_array_option_verification(): void {
        $this->resetAfterTest();
        $conversationcontext = [
            ['sender' => 'user', 'message' => 'Grüße & Fragen: 5 < 6, äöüß'],
            ['sender' => 'ai', 'message' => 'Antwort', 'count' => 3, 'final' => true],
        ];
        $requestoptions = new request_options(
            new \aipurpose_chat\purpose(),
            \context_system::instance(),
            'block_ai_chat',
            ['conversationcontext' => $conversationcontext]
        );
        $requestoptions->sanitize_options();
        $result = $requestoptions->get_options()['conversationcontext'];
        // The chat purpose prepends its system message, the user supplied entries have to stay untouched.
        $this->assertEquals($conversationcontext, array_slice($result, 1));
    }
}

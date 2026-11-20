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

namespace aipurpose_searchquery;

use local_ai_manager\request_options;

/**
 * Tests for the searchquery purpose.
 *
 * @package    aipurpose_searchquery
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(purpose::class)]
final class purpose_test extends \basic_testcase {
    /**
     * Tests that the conversation is passed as data inside the only system message, without system messages of the caller.
     */
    public function test_get_additional_request_options(): void {
        $purpose = new purpose();
        $systemprompt = purpose::get_default_systemprompt();

        $this->assertSame(
            ['conversationcontext' => [['sender' => 'system', 'message' => $systemprompt]]],
            $purpose->get_additional_request_options([])
        );

        $result = $purpose->get_additional_request_options([
            'conversationcontext' => [
                // System messages of the caller contain instructions for another purpose and are dropped.
                ['sender' => 'system', 'message' => 'You are a pirate.'],
                ['sender' => 'user', 'message' => 'What does the curriculum say about assessment?'],
                ['sender' => 'ai', 'message' => "1. Written exams\n2. Oral contributions\n"],
            ],
        ]);
        $expected = $systemprompt . "\n\n═════ CONVERSATION HISTORY ═════\n\n"
            . "───── USER ─────\n\nWhat does the curriculum say about assessment?\n\n"
            . "───── ASSISTANT ─────\n\n1. Written exams\n2. Oral contributions";
        $this->assertSame(['conversationcontext' => [['sender' => 'system', 'message' => $expected]]], $result);
    }

    /**
     * Tests that the latest message is put after its separator line.
     */
    public function test_format_prompt_text(): void {
        $purpose = new purpose();
        $requestoptions = $this->createMock(request_options::class);

        $this->assertSame(
            "═════ LATEST MESSAGE ═════\n\nAnd the second point?",
            $purpose->format_prompt_text(' And the second point? ', $requestoptions)
        );
    }

    /**
     * Tests that the output is trimmed and discarded if it exceeds the maximum length.
     */
    public function test_format_output(): void {
        $purpose = new purpose();
        $maxquery = str_repeat('ä', purpose::MAX_QUERY_LENGTH);

        $this->assertSame('assessment curriculum', $purpose->format_output(" assessment curriculum\n"));
        $this->assertSame($maxquery, $purpose->format_output($maxquery . ' '));
        $this->assertSame('', $purpose->format_output($maxquery . 'ä'));
    }

    /**
     * Tests that the word limit in the system prompt keeps a safe distance to the maximum length of the search query.
     */
    public function test_get_default_systemprompt_word_limit(): void {
        $this->assertSame(1, preg_match('/not be longer than (\d+) words/', purpose::get_default_systemprompt(), $matches));
        // Even with an average of 8 characters per word (including the space) the query has to fit.
        $this->assertGreaterThan(0, (int) $matches[1]);
        $this->assertLessThanOrEqual(purpose::MAX_QUERY_LENGTH, (int) $matches[1] * 8);
    }
}

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
 * Test class for the request_options class.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Umut Zerman
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_manager\request_options
 */
final class request_options_test extends \advanced_testcase {
    /**
     * Test that array options are cleaned recursively while keeping their structure and non-string values.
     */
    public function test_sanitize_options_cleans_array_options(): void {
        $purpose = new \aipurpose_questiongeneration\purpose();
        $requestoptions = new request_options($purpose, \context_system::instance(), 'local_ai_manager');
        $requestoptions->set_options([
            'conversationcontext' => [
                ['sender' => 'user', 'message' => '<b>Hello</b> world', 'id' => 5, 'flag' => false, 'empty' => null],
            ],
        ]);

        $requestoptions->sanitize_options();

        $this->assertSame([
            'conversationcontext' => [
                ['sender' => 'user', 'message' => 'Hello world', 'id' => 5, 'flag' => false, 'empty' => null],
            ],
        ], $requestoptions->get_options());
    }
}

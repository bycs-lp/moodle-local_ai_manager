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

namespace local_ai_manager\hook;

/**
 * Test class for the purpose_usage hook.
 *
 * @package    local_ai_manager
 * @copyright  2025 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class purpose_usage_test extends \advanced_testcase {
    /**
     * Tests the get_purposes_usage_info method which rewrites the structure of the array.
     *
     * @covers \local_ai_manager\hook\purpose_usage::get_purposes_usage_info
     */
    public function test_get_purposes_usage_info(): void {
        $hook = new purpose_usage();
        $hook->set_component_displayname('testcomponent1', 'displaynamecomponent1');
        $hook->set_component_displayname('testcomponent2', 'displaynamecomponent2');
        $hook->add_purpose_usage_description('chat', 'testcomponent1', 'testcomponent1 description first place chat');
        $hook->add_purpose_usage_description('chat', 'testcomponent1', 'testcomponent1 description second place chat');
        $hook->add_purpose_usage_description('chat', 'testcomponent2', 'testcomponent2 description first place chat');
        $hook->add_purpose_usage_description('chat', 'testcomponent2', 'testcomponent2 description second place chat');
        $hook->add_purpose_usage_description(
            'translate',
            'testcomponent1',
            'description of the first place for translating'
        );

        $expected = [
            'chat' => [
                'testcomponent1' => [
                    'testcomponent1 description first place chat',
                    'testcomponent1 description second place chat',
                ],
                'testcomponent2' => [
                    'testcomponent2 description first place chat',
                    'testcomponent2 description second place chat',
                ],
            ],
            'translate' => [
                'testcomponent1' => [
                    'description of the first place for translating',
                ],
            ],
        ];
        $this->assertEquals($expected, $hook->get_purposes_usage_info());
    }
}

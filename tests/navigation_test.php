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
 * Tests for extending the primary navigation.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Paul Baumgart-Ouahid
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class navigation_test extends \advanced_testcase {
    /**
     * Ensure the AI manager entry gets a stable key in primary navigation.
     *
     * @covers \local_ai_manager\local\hook_callbacks::extend_primary_navigation
     */
    public function test_extend_navigation(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('addnavigationentry', 1, 'local_ai_manager');

        $PAGE = new \moodle_page();
        $PAGE->set_url('/');

        $primarynav = new \core\navigation\views\primary($PAGE);
        $primarynav->initialise();

        $keys = $primarynav->get_children_key_list();
        $this->assertContains('local_ai_manager', $keys);
        $this->assertNotContains(4, $keys);

        $node = $primarynav->get('local_ai_manager');
        $this->assertInstanceOf(\navigation_node::class, $node);
        $this->assertSame(get_string('aiadministrationlink', 'local_ai_manager'), $node->text);
        $this->assertStringEndsWith('/local/ai_manager/tenant_config.php', $node->action()->get_path());
    }
}

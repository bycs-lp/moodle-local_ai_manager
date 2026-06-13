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

use core\hook\navigation\primary_extend;
use core\navigation\views\primary;
use local_ai_manager\local\access_manager;
use local_ai_manager\local\hook_callbacks;
use local_ai_manager\local\tenant;
use navigation_node;

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
     * Build a primary navigation view and run the plugin callback against it via the hook.
     *
     * The callback under test is invoked exactly as core does it: through the
     * {@see \core\hook\navigation\primary_extend} hook wrapping the primary view.
     * We deliberately do not call $primarynav->initialise() so that the view contains
     * only the nodes contributed by our own callback (no core 'home' etc. nodes).
     *
     * @return primary the primary navigation view after our callback has run
     */
    private function run_callback(): primary {
        global $PAGE;

        $PAGE = new \moodle_page();
        $PAGE->set_url('/');
        $PAGE->set_context(\context_system::instance());

        $primarynav = new primary($PAGE);
        hook_callbacks::extend_primary_navigation(new primary_extend($primarynav));
        return $primarynav;
    }

    /**
     * Make the two injected guard dependencies of the callback resolve deterministically.
     *
     * @param bool $istenantmanager value returned by access_manager::is_tenant_manager()
     * @param bool $istenantallowed value returned by tenant::is_tenant_allowed()
     */
    private function stub_guards(bool $istenantmanager, bool $istenantallowed): void {
        $accessmanager = $this->createMock(access_manager::class);
        $accessmanager->method('is_tenant_manager')->willReturn($istenantmanager);
        \core\di::set(access_manager::class, $accessmanager);

        $tenant = $this->createMock(tenant::class);
        $tenant->method('is_tenant_allowed')->willReturn($istenantallowed);
        \core\di::set(tenant::class, $tenant);
    }

    /**
     * The AI manager entry must be added under the stable string key, never a numeric index.
     *
     * This is the core regression of MBS-10704: without an explicit key,
     * {@see \core\navigation\navigation_node::add_node()} falls back to using the child
     * count as the key (e.g. the numeric index 4), which is unstable.
     *
     * @covers \local_ai_manager\local\hook_callbacks::extend_primary_navigation
     */
    public function test_extend_navigation_uses_stable_key(): void {
        $this->resetAfterTest();
        set_config('addnavigationentry', 1, 'local_ai_manager');
        $this->stub_guards(true, true);

        $primarynav = $this->run_callback();

        $keys = $primarynav->get_children_key_list();
        $this->assertContains('local_ai_manager', $keys);
        // Regression guard: must not fall back to a numeric index.
        $this->assertNotContains(4, $keys, 'The navigation node must not use a numeric fallback key.');
        foreach ($keys as $key) {
            $this->assertIsNotInt($key, 'Primary navigation keys contributed by the plugin must be stable strings.');
        }

        /** @var navigation_node $node */
        $node = $primarynav->get('local_ai_manager');
        $this->assertInstanceOf(navigation_node::class, $node);
        $this->assertSame(get_string('aiadministrationlink', 'local_ai_manager'), (string) $node->text);
        $this->assertStringEndsWith('/local/ai_manager/tenant_config.php', $node->action()->get_path());
    }

    /**
     * Data provider for the cases in which no navigation node must be added.
     *
     * @return array<string, array{bool, bool, bool}> addnavigationentry, istenantmanager, istenantallowed
     */
    public static function no_node_provider(): array {
        return [
            'setting_disabled' => [false, true, true],
            'not_a_tenant_manager' => [true, false, true],
            'tenant_not_allowed' => [true, true, false],
        ];
    }

    /**
     * The node must not be added when the feature is disabled or the user lacks permission.
     *
     * @param bool $addnavigationentry whether the navigation entry setting is enabled
     * @param bool $istenantmanager whether the user is a tenant manager
     * @param bool $istenantallowed whether the tenant is allowed
     *
     * @covers \local_ai_manager\local\hook_callbacks::extend_primary_navigation
     * @dataProvider no_node_provider
     */
    public function test_extend_navigation_does_not_add_node(
        bool $addnavigationentry,
        bool $istenantmanager,
        bool $istenantallowed
    ): void {
        $this->resetAfterTest();
        set_config('addnavigationentry', $addnavigationentry ? 1 : 0, 'local_ai_manager');
        $this->stub_guards($istenantmanager, $istenantallowed);

        $primarynav = $this->run_callback();

        $this->assertNotContains('local_ai_manager', $primarynav->get_children_key_list());
        $this->assertFalse($primarynav->get('local_ai_manager'));
    }
}

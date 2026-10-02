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

/**
 * Tests for the tenant_factory class and the tenant dependent objects following it.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_manager\local\tenant_factory
 * @covers     \local_ai_manager\local\hook_callbacks
 * @covers     \local_ai_manager\local\config_manager
 * @covers     \local_ai_manager\local\access_manager
 */
final class tenant_factory_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->redirectHook(\local_ai_manager\hook\custom_tenant::class, fn() => null);
    }

    /**
     * Tests that the tenant cannot be retrieved from the DI container directly.
     */
    public function test_tenant_not_retrievable_from_di(): void {
        $this->setUser($this->getDataGenerator()->create_user(['institution' => 'schoola']));
        $this->assertEquals('schoola', \core\di::get(tenant_factory::class)->get()->get_identifier());
        $this->expectException(\coding_exception::class);
        \core\di::get(tenant::class);
    }

    /**
     * Tests getting, setting and resetting the current tenant.
     */
    public function test_get_set_reset(): void {
        $usera = $this->getDataGenerator()->create_user(['institution' => 'schoola']);
        $userb = $this->getDataGenerator()->create_user(['institution' => 'schoolb']);
        $this->setUser($usera);
        $tenantfactory = \core\di::get(tenant_factory::class);

        $this->assertEquals('schoola', $tenantfactory->get()->get_identifier());
        // Without an explicitly set tenant, a change of the current user is respected.
        $this->setUser($userb);
        $this->assertEquals('schoolb', $tenantfactory->get()->get_identifier());

        $tenantfactory->set(new tenant('schoolc'));
        $this->assertEquals('schoolc', $tenantfactory->get()->get_identifier());

        $tenantfactory->reset();
        $this->assertEquals('schoolb', $tenantfactory->get()->get_identifier());
    }

    /**
     * Creates a separate tenant factory with the given tenant being set.
     *
     * @param string $identifier the tenant identifier
     * @return tenant_factory the tenant factory
     */
    private function create_tenant_factory(string $identifier): tenant_factory {
        $tenantfactory = new tenant_factory();
        $tenantfactory->set(new tenant($identifier));
        return $tenantfactory;
    }

    /**
     * Tests that the config manager from the DI container follows the current tenant, a config manager with a fixed tenant not.
     */
    public function test_config_manager_follows_current_tenant(): void {
        $this->setUser($this->getDataGenerator()->create_user(['institution' => 'schoola']));
        (new config_manager($this->create_tenant_factory('schoola')))->set_config('somekey', 'valuea');
        (new config_manager($this->create_tenant_factory('schoolb')))->set_config('somekey', 'valueb');

        $configmanager = \core\di::get(config_manager::class);
        $fixedconfigmanager = new config_manager($this->create_tenant_factory('schoola'));
        $this->assertEquals('schoola', $configmanager->get_tenant()->get_identifier());
        $this->assertEquals('valuea', $configmanager->get_config('somekey'));

        \core\di::get(tenant_factory::class)->set(new tenant('schoolb'));
        $this->assertSame($configmanager, \core\di::get(config_manager::class));
        $this->assertEquals('schoolb', $configmanager->get_tenant()->get_identifier());
        $this->assertEquals('valueb', $configmanager->get_config('somekey'));
        $this->assertEquals('valuea', $fixedconfigmanager->get_config('somekey'));

        \core\di::get(tenant_factory::class)->reset();
        $this->assertEquals('valuea', $configmanager->get_config('somekey'));
    }

    /**
     * Tests that the access manager from the DI container follows the current tenant.
     */
    public function test_access_manager_follows_current_tenant(): void {
        $this->setUser($this->getDataGenerator()->create_user(['institution' => 'schoola']));
        $accessmanager = \core\di::get(access_manager::class);
        $this->assertTrue($accessmanager->is_tenant_member());

        \core\di::get(tenant_factory::class)->set(new tenant('schoolb'));
        $this->assertFalse($accessmanager->is_tenant_member());
        $this->assertTrue((new access_manager($this->create_tenant_factory('schoola')))->is_tenant_member());
    }
}

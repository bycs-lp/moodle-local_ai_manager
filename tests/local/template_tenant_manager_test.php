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

use local_ai_manager\base_instance;
use local_ai_manager\base_purpose;

/**
 * Tests for the template_tenant_manager class.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(template_tenant_manager::class)]
final class template_tenant_manager_test extends \advanced_testcase {
    /**
     * Tests determining if there is a template configuration.
     */
    public function test_has_template_configuration(): void {
        $this->resetAfterTest();
        $manager = \core\di::get(template_tenant_manager::class);
        $this->assertTrue($manager->get_template_tenant()->is_template_tenant());
        $this->assertFalse($manager->has_template_configuration());

        // The template configuration is offered as soon as the template tenant is enabled, even without AI tools.
        $templateconfigmanager = new config_manager($this->create_tenant_factory($manager->get_template_tenant()));
        $templateconfigmanager->set_config('tenantenabled', '1');
        $this->assertTrue($manager->has_template_configuration());

        $templateconfigmanager->set_config('tenantenabled', '0');
        $this->assertFalse($manager->has_template_configuration());
    }

    /**
     * Tests applying the configuration of the template tenant to another tenant.
     */
    public function test_apply_template(): void {
        $this->resetAfterTest();
        /** @var \local_ai_manager_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_ai_manager');

        $templatetenant = new tenant(tenant::TEMPLATE_IDENTIFIER);
        $templateinstancea = $generator->create_instance(['name' => 'Template A', 'tenant' => tenant::TEMPLATE_IDENTIFIER]);
        $templateinstanceb = $generator->create_instance(['name' => 'Template B', 'tenant' => tenant::TEMPLATE_IDENTIFIER]);
        $templateconfigmanager = new config_manager($this->create_tenant_factory($templatetenant));
        $templateconfigmanager->set_config('tenantenabled', '1');
        $chatbasickey = base_purpose::get_purpose_tool_config_key('chat', userinfo::ROLE_BASIC);
        $chatextendedkey = base_purpose::get_purpose_tool_config_key('chat', userinfo::ROLE_EXTENDED);
        $translatebasickey = base_purpose::get_purpose_tool_config_key('translate', userinfo::ROLE_BASIC);
        $imggenbasickey = base_purpose::get_purpose_tool_config_key('imggen', userinfo::ROLE_BASIC);
        $templateconfigmanager->set_config($chatbasickey, $templateinstancea->get_id());
        $templateconfigmanager->set_config($chatextendedkey, $templateinstanceb->get_id());
        $templateconfigmanager->set_config($translatebasickey, $templateinstancea->get_id());

        $tenant = new tenant('tenanta');
        $oldinstance = $generator->create_instance(['name' => 'Old instance', 'tenant' => 'tenanta']);
        $configmanager = new config_manager($this->create_tenant_factory($tenant));
        $configmanager->set_config($chatbasickey, $oldinstance->get_id());
        $configmanager->set_config($imggenbasickey, $oldinstance->get_id());
        $configmanager->set_config('tenantenabled', '1');

        \core\di::get(template_tenant_manager::class)->apply_template($tenant);

        $instances = [];
        foreach (base_instance::get_all_instances(tenant: $tenant) as $instance) {
            $instances[$instance->get_name()] = $instance;
        }
        $this->assertEqualsCanonicalizing(['Template A', 'Template B'], array_keys($instances));
        $this->assertFalse((new base_instance($oldinstance->get_id()))->record_exists());
        $this->assertNotEquals($templateinstancea->get_id(), $instances['Template A']->get_id());
        $this->assertSame('chatgpt', $instances['Template A']->get_connector());

        $configmanager = new config_manager($this->create_tenant_factory($tenant));
        $purposeconfigbasic = $configmanager->get_purpose_config(userinfo::ROLE_BASIC);
        $purposeconfigextended = $configmanager->get_purpose_config(userinfo::ROLE_EXTENDED);
        $this->assertEquals($instances['Template A']->get_id(), $purposeconfigbasic['chat']);
        $this->assertEquals($instances['Template A']->get_id(), $purposeconfigbasic['translate']);
        $this->assertNull($purposeconfigbasic['imggen']);
        $this->assertEquals($instances['Template B']->get_id(), $purposeconfigextended['chat']);
        // Other configuration is not being touched.
        $this->assertTrue($configmanager->is_tenant_enabled());

        // The template tenant itself stays unchanged.
        $this->assertCount(2, base_instance::get_all_instances(tenant: $templatetenant));
        $templateconfigmanager = new config_manager($this->create_tenant_factory($templatetenant));
        $this->assertEquals(
            $templateinstancea->get_id(),
            $templateconfigmanager->get_purpose_config(userinfo::ROLE_BASIC)['chat']
        );
    }

    /**
     * Tests that applying the template fails if the template tenant is disabled.
     */
    public function test_apply_template_without_template_configuration(): void {
        $this->resetAfterTest();
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('exception_notemplateconfiguration', 'local_ai_manager'));
        \core\di::get(template_tenant_manager::class)->apply_template(new tenant('tenanta'));
    }

    /**
     * Tests that the template cannot be applied to the template tenant itself.
     */
    public function test_apply_template_to_template_tenant(): void {
        $this->resetAfterTest();
        $this->expectException(\coding_exception::class);
        \core\di::get(template_tenant_manager::class)->apply_template(new tenant(tenant::TEMPLATE_IDENTIFIER));
    }

    /**
     * Creates a separate tenant factory with the given tenant being set.
     *
     * @param tenant $tenant the tenant
     * @return tenant_factory the tenant factory
     */
    private function create_tenant_factory(tenant $tenant): tenant_factory {
        $tenantfactory = new tenant_factory();
        $tenantfactory->set($tenant);
        return $tenantfactory;
    }
}

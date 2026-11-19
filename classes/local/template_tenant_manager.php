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
 * Manager for the template tenant whose configuration can be applied to other tenants as a starting configuration.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_tenant_manager {
    /**
     * Returns the template tenant.
     *
     * @return tenant the template tenant
     */
    public function get_template_tenant(): tenant {
        return new tenant(tenant::TEMPLATE_IDENTIFIER);
    }

    /**
     * Returns if there is a template configuration which can be offered to other tenants.
     *
     * The template tenant being enabled is used as switch for offering the template configuration at all.
     *
     * @return bool true if the template tenant is enabled
     */
    public function has_template_configuration(): bool {
        $configmanager = new config_manager($this->create_tenant_factory($this->get_template_tenant()));
        return $configmanager->is_tenant_enabled();
    }

    /**
     * Replaces all AI tools and purpose assignments of the given tenant by copies of the ones of the template tenant.
     *
     * @param tenant $tenant the tenant to apply the template configuration to
     * @throws \coding_exception if the given tenant is the template tenant itself
     * @throws \moodle_exception if the template configuration is not being offered (template tenant disabled)
     */
    public function apply_template(tenant $tenant): void {
        global $DB;
        if ($tenant->is_template_tenant()) {
            throw new \coding_exception('The template configuration cannot be applied to the template tenant itself.');
        }
        if (!$this->has_template_configuration()) {
            throw new \moodle_exception('exception_notemplateconfiguration', 'local_ai_manager');
        }
        $templatetenant = $this->get_template_tenant();

        $transaction = $DB->start_delegated_transaction();

        foreach (base_instance::get_all_instances(tenant: $tenant) as $instance) {
            $instance->delete();
        }

        $instanceidmapping = [];
        foreach (base_instance::get_all_instances(tenant: $templatetenant) as $templateinstance) {
            $instanceidmapping[$templateinstance->get_id()] = $templateinstance->copy_to_tenant($tenant)->get_id();
        }

        // We intentionally do not use dependency injection here, because we need config managers for specific tenants.
        $templateconfigmanager = new config_manager($this->create_tenant_factory($templatetenant));
        $configmanager = new config_manager($this->create_tenant_factory($tenant));
        foreach ([userinfo::ROLE_BASIC, userinfo::ROLE_EXTENDED] as $role) {
            foreach ($templateconfigmanager->get_purpose_config($role) as $purpose => $templateinstanceid) {
                $configkey = base_purpose::get_purpose_tool_config_key($purpose, $role);
                if (!is_null($templateinstanceid) && array_key_exists(intval($templateinstanceid), $instanceidmapping)) {
                    $configmanager->set_config($configkey, $instanceidmapping[intval($templateinstanceid)]);
                } else {
                    $configmanager->unset_config($configkey);
                }
            }
        }

        $transaction->allow_commit();
    }

    /**
     * Creates a separate tenant factory for the given tenant.
     *
     * @param tenant $tenant the tenant the factory should provide
     * @return tenant_factory the tenant factory
     */
    private function create_tenant_factory(tenant $tenant): tenant_factory {
        $tenantfactory = new tenant_factory();
        $tenantfactory->set($tenant);
        return $tenantfactory;
    }
}

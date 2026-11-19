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

namespace local_ai_manager\output;

use html_writer;
use local_ai_manager\base_instance;
use local_ai_manager\local\config_manager;
use local_ai_manager\local\template_tenant_manager;
use local_ai_manager\local\tenant;
use local_ai_manager\local\tenant_factory;
use local_ai_manager\local\userinfo;
use moodle_url;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * Instance table widget shown on the tenant_config.php page.
 *
 * @package    local_ai_manager
 * @copyright  2024 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class instancetable implements renderable, templatable {
    /**
     * Constructor.
     *
     * @param ?tenant $tenant the tenant whose instances should be shown, null to use the current tenant
     * @param bool $preview true if the table should only be shown read-only, for example the table of the template tenant
     */
    public function __construct(
        /** @var ?tenant $tenant the tenant whose instances should be shown, null to use the current tenant */
        private readonly ?tenant $tenant = null,
        /** @var bool $preview true if the table should only be shown read-only */
        private readonly bool $preview = false
    ) {
    }

    #[\Override]
    public function export_for_template(renderer_base $output): stdClass {
        $tenant = $this->tenant ?? \core\di::get(tenant_factory::class)->get();
        // We intentionally do not use the config manager from the DI container, because it always works with the current
        // tenant of the request, but this table may show another tenant (the template tenant in the preview).
        $tenantfactory = new tenant_factory();
        $tenantfactory->set($tenant);
        $configmanager = new config_manager($tenantfactory);

        $instances = [];

        // Purposes with role basic.
        $purposeconfigrolebasic = $configmanager->get_purpose_config(userinfo::ROLE_BASIC);
        $purposeswithtoolrolebasic = [];
        foreach ($purposeconfigrolebasic as $purpose => $instanceid) {
            if (!is_null($instanceid)) {
                $purposeswithtoolrolebasic[] = $purpose;
            }
        }
        $purposesheadingrolebasic = get_string('purposesheading', 'local_ai_manager', [
            'role' => get_string('role_basic', 'local_ai_manager'),
            'currentcount' => count($purposeswithtoolrolebasic),
            'maxcount' => count($purposeconfigrolebasic),
        ]);

        // Purposes with role extended.
        $purposeconfigroleextended = $configmanager->get_purpose_config(userinfo::ROLE_EXTENDED);
        $purposeswithtoolroleextended = [];
        foreach ($purposeconfigroleextended as $purpose => $instanceid) {
            if (!is_null($instanceid)) {
                $purposeswithtoolroleextended[] = $purpose;
            }
        }
        $purposesheadingroleextended = get_string('purposesheading', 'local_ai_manager', [
            'role' => get_string('role_extended', 'local_ai_manager'),
            'currentcount' => count($purposeswithtoolroleextended),
            'maxcount' => count($purposeconfigroleextended),
        ]);

        foreach (base_instance::get_all_instances(tenant: $tenant) as $instance) {
            $purposesrolebasic = [];
            foreach ($purposeconfigrolebasic as $purpose => $instanceid) {
                if (intval($instanceid) === $instance->get_id()) {
                    $purposesrolebasic[] = ['fullname' => get_string('pluginname', 'aipurpose_' . $purpose)];
                }
            }
            $purposesroleextended = [];
            foreach ($purposeconfigroleextended as $purpose => $instanceid) {
                if (intval($instanceid) === $instance->get_id()) {
                    $purposesroleextended[] = ['fullname' => get_string('pluginname', 'aipurpose_' . $purpose)];
                }
            }
            $linkedname = $instance->is_enabled() && !$this->preview
                ? html_writer::link(
                    new moodle_url(
                        '/local/ai_manager/edit_instance.php',
                        ['id' => $instance->get_id(), 'tenant' => $tenant->get_identifier()]
                    ),
                    s($instance->get_name())
                )
                : s($instance->get_name());

            $instances[] = [
                'name' => $linkedname,
                'toolname' => get_string('pluginname', 'aitool_' . $instance->get_connector()),
                'model' => $instance->get_model_name() === base_instance::PRECONFIGURED_MODEL
                    ? get_string('preconfiguredmodel', 'local_ai_manager')
                    : $instance->get_model_name(),
                'enabled' => $instance->is_enabled(),
                'purposesrolebasic' => $purposesrolebasic,
                'purposesroleextended' => $purposesroleextended,
                'nopurposeslink' => $this->preview ? '' : html_writer::link(
                    new moodle_url(
                        '/local/ai_manager/purpose_config.php',
                        ['tenant' => $tenant->get_identifier()]
                    ),
                    '<i class="fa fa-arrow-right"></i> ' . get_string('assignpurposes', 'local_ai_manager')
                ),
            ];
        }
        return (object) [
            'tenant' => $tenant->get_identifier(),
            'purposesheadingrolebasic' => $purposesheadingrolebasic,
            'purposesheadingroleextended' => $purposesheadingroleextended,
            'instances' => $instances,
            'templatetenantmodal' => $this->preview ? false : $this->get_template_tenant_modal_context($tenant, $output),
        ];
    }

    /**
     * Returns the context for the modal showing the configuration of the template tenant.
     *
     * @param tenant $tenant the tenant this table is being shown for
     * @param renderer_base $output the renderer
     * @return array|false the context for the modal, false if there is no template tenant to offer
     */
    private function get_template_tenant_modal_context(tenant $tenant, renderer_base $output): array|false {
        $templatetenantmanager = \core\di::get(template_tenant_manager::class);
        if ($tenant->is_template_tenant() || !$templatetenantmanager->has_template_configuration()) {
            return false;
        }
        return [
            'instancetable' => (new self($templatetenantmanager->get_template_tenant(), true))->export_for_template($output),
            'applyurl' => (new moodle_url(
                '/local/ai_manager/tenant_config.php',
                ['tenant' => $tenant->get_identifier(), 'applytemplatetenant' => 1, 'sesskey' => sesskey()]
            ))->out(false),
        ];
    }
}

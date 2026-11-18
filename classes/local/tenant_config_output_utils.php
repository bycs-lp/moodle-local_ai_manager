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

use moodle_url;

/**
 * Utils class for aggregating code to avoid duplication.
 *
 * @package    local_ai_manager
 * @copyright  2024 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tenant_config_output_utils {
    /**
     * @var string[] the tenant config pages (relative to '/local/ai_manager/') available for the template tenant
     *
     * No user belongs to the template tenant, so only the pages for configuring AI tools and purposes make sense.
     */
    private const TEMPLATE_TENANT_PAGES = ['tenant_config.php', 'edit_instance.php', 'purpose_config.php'];

    /**
     * Returns if a tenant config page is available for the given tenant.
     *
     * @param tenant $tenant the tenant to check
     * @param string $page the file name of the page relative to '/local/ai_manager/', for example 'quota_config.php'
     * @return bool true if the page is available for the tenant
     */
    public static function is_page_available(tenant $tenant, string $page): bool {
        return !$tenant->is_template_tenant() || in_array($page, self::TEMPLATE_TENANT_PAGES, true);
    }

    /**
     * Helper function which is being called from every tenant config (sub)page.
     *
     * Important to control access and set important basic settings which are identical for all the pages.
     *
     * @param moodle_url $url the moodle url object of the current page
     */
    public static function setup_tenant_config_page(moodle_url $url): void {
        global $PAGE, $SESSION;
        $tenantid = optional_param('tenant', '', PARAM_TEXT);

        if (!empty($tenantid)) {
            $tenant = new \local_ai_manager\local\tenant($tenantid);
            \core\di::get(tenant_factory::class)->set($tenant);
        }
        $tenant = \core\di::get(tenant_factory::class)->get();
        $accessmanager = \core\di::get(\local_ai_manager\local\access_manager::class);
        $accessmanager->require_tenant_manager();
        // Dynamic tables external service does not support passing any parameters to the table nor can you pass
        // optional param or anything else. So only storing the current tenant in the session is left as possibility to
        // track the currently selected tenant.
        $SESSION->local_ai_manager_tenant = $tenant;

        $url->param('tenant', $tenant->get_identifier());
        $PAGE->set_url($url);
        $PAGE->set_context($tenant->get_context());
        $PAGE->set_pagelayout('admin');

        $strtitle = get_string('tenantconfig_heading', 'local_ai_manager');
        $strtitle .= ' (' . $tenant->get_identifier() . ')';
        $PAGE->set_title($strtitle);
        $PAGE->set_heading($strtitle);
        $PAGE->navbar->add($strtitle);
        $PAGE->set_secondary_navigation(false);

        if (!$tenant->is_tenant_allowed()) {
            throw new \moodle_exception('exception_tenantnotallowed', 'local_ai_manager');
        }
        if (!self::is_page_available($tenant, basename($url->get_path()))) {
            throw new \moodle_exception('exception_templatetenantpage', 'local_ai_manager');
        }
    }

    /**
     * Renders the hint shown below the heading of the tenant config pages of the template tenant.
     *
     * Has to be called on every page available for the template tenant, directly after the page header has been printed.
     *
     * @return string the HTML of the hint, empty string if the current tenant is not the template tenant
     */
    public static function render_template_tenant_hint(): string {
        global $OUTPUT;
        if (!\core\di::get(tenant_factory::class)->get()->is_template_tenant()) {
            return '';
        }
        return $OUTPUT->render_from_template('local_ai_manager/templatetenanthint', []);
    }
}

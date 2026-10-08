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

/**
 * Configuration page for tenants.
 *
 * @package    local_ai_manager
 * @copyright  2024 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_ai_manager\form\rights_config_form;
use local_ai_manager\local\tenant_factory;
use local_ai_manager\local\tenant_config_output_utils;
use local_ai_manager\local\userinfo;
use local_ai_manager\output\tenantnavbar;
use local_ai_manager\table\rights_config_table;

require_once(dirname(__FILE__) . '/../../config.php');
require_login();

global $CFG, $DB, $OUTPUT, $PAGE, $SESSION, $USER;

tenant_config_output_utils::setup_tenant_config_page(new moodle_url('/local/ai_manager/rights_config.php'));

$tenant = \core\di::get(tenant_factory::class)->get();
$returnurl = new moodle_url('/local/ai_manager/tenant_config.php', ['tenant' => $tenant->get_identifier()]);

$rightsconfigform = new rights_config_form(null, ['tenant' => $tenant]);

// Standard form processing if statement.
if ($rightsconfigform->is_cancelled()) {
    redirect($returnurl);
} else if ($data = $rightsconfigform->get_data()) {
    $userids = explode(';', $data->userids);
    foreach ($userids as $userid) {
        $user = \core_user::get_user($userid);
        $tenantfield = get_config('local_ai_manager', 'tenantcolumn');
        if (!$user) {
            throw new moodle_exception('exception_usernotexists', 'local_ai_manager', '', '', 'User ID: ' . $userid);
        }
        if ($user->{$tenantfield} !== $tenant->get_sql_identifier()) {
            throw new moodle_exception('exception_changestatusnotallowed', 'local_ai_manager', '', '', 'User ID: ' . $userid);
        }
        $userinfo = new userinfo($userid);
        switch ($data->action) {
            case rights_config_form::ACTION_CHANGE_LOCK_STATE:
                $userinfo->set_locked($data->lockstate === rights_config_form::ACTIONOPTION_CHANGE_LOCK_STATE_LOCKED);
                break;
            case rights_config_form::ACTION_ASSIGN_ROLE:
                $role = intval($data->role);
                $userinfo->set_role($role);
                break;
            case rights_config_form::ACTION_CHANGE_CONFIRM_STATE:
                $userinfo->set_confirmed($data->confirmstate === rights_config_form::ACTIONOPTION_CHANGE_CONFIRM_STATE_CONFIRM);
                break;
            case rights_config_form::ACTION_CHANGE_SCOPE:
                $userinfo->set_scope(intval($data->scope));
                break;
            default:
                throw new \coding_exception('Unknown action: ' . $data->action);
        }
        $userinfo->store();
    }

    redirect($PAGE->url, get_string('userstatusupdated', 'local_ai_manager'));
} else {
    echo $OUTPUT->header();

    $tenantnavbar = new tenantnavbar('rights_config.php');
    echo $OUTPUT->render($tenantnavbar);
    echo $OUTPUT->heading(get_string('rightsconfig', 'local_ai_manager'), 2, 'text-center');

    // Render rights table.
    $uniqid = 'rights-config-table-' . uniqid();
    $renderable = new \local_ai_manager\output\rights_config_table_filter($tenant->get_context(), $uniqid);
    $templatecontext = $renderable->export_for_template($OUTPUT);
    echo $OUTPUT->render_from_template('local_ai_manager/table_filter', $templatecontext);
    $rightstable = new rights_config_table($uniqid);
    $rightstable->out(30, false);
    $rightsconfigform->display();
    $PAGE->requires->js_call_amd('local_ai_manager/rights_config_table', 'init', ['id' => $uniqid]);
}

echo $OUTPUT->footer();

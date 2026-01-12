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
 * Prompt viewer page.
 *
 * @package    local_ai_manager
 * @copyright  2025 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_ai_manager\ai_manager_utils;
use local_ai_manager\form\context_selector_form;
use local_ai_manager\local\tenant;
use local_ai_manager\local\view_prompts_table;
use local_ai_manager\output\tenantnavbar;

require_once(dirname(__FILE__) . '/../../config.php');
require_login();

global $CFG, $DB, $OUTPUT, $PAGE, $USER;

global $PAGE;
$tenantid = optional_param('tenant', '', PARAM_TEXT);
$contextid = optional_param('contextid', '', PARAM_INT);

if (!empty($tenantid) && !empty($contextid)) {
    // If tenant is specified the context will be the tenant's context.
    throw new \moodle_exception('error_nocontextiftenant', 'local_ai_manager');
}

if (!empty($tenantid)) {
    $tenant = new tenant($tenantid);
    \core\di::set(tenant::class, $tenant);
}
$tenant = \core\di::get(tenant::class);
$accessmanager = \core\di::get(\local_ai_manager\local\access_manager::class);
$accessmanager->require_tenant_member();

$context = empty($contextid) ? $tenant->get_context() : \context::instance_by_id($contextid);
if ($context->contextlevel === CONTEXT_COURSE) {
    $PAGE->set_course(get_course($context->instanceid));
    require_capability('local/ai_manager:viewprompts', $context);
} else {
    require_capability('local/ai_manager:viewtenantprompts', $context);
}

$url = new moodle_url('/local/ai_manager/view_prompts.php', ['contextid' => $context->id]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->add_body_class('limitcontentwidth');

$strtitle = get_string('viewprompts', 'local_ai_manager');
$PAGE->set_title($strtitle);
$PAGE->set_heading($strtitle);
$PAGE->navbar->add($strtitle);
if ($context->contextlevel !== CONTEXT_COURSE) {
    $PAGE->set_secondary_navigation(false);
}

$returnurl = new moodle_url('/local/ai_manager/view_prompts.php', ['tenant' => $tenant->get_identifier()]);
// Will return the config manager for the current user.
$configmanager = \core\di::get(\local_ai_manager\local\config_manager::class);

$contextselectorform = new context_selector_form(null, ['maincontext' => $context]);

// Standard form processing if statement.
if ($contextselectorform->is_cancelled()) {
    redirect($returnurl);
} else {
    echo $OUTPUT->header();
    if ($accessmanager->is_tenant_manager() && $context->id === $tenant->get_context()->id) {
        $tenantnavbar = new tenantnavbar('view_prompts.php');
        echo $OUTPUT->render($tenantnavbar);
    }

    $contextselectorform->set_data(['contextid' => $context->id]);
    $contextselectorform->display();

    // Render View prompts table.
    echo html_writer::start_div(
        '',
        [
            'id' => 'local_ai_manager-viewprompts',
            'data-contextid' => $context->id,
            'data-contextdisplayname' => ai_manager_utils::get_context_displayname($context, $tenant),
        ]
    );

    $uniqid = 'view-prompts-table-' . uniqid();
    $viewpromptstable = new view_prompts_table($uniqid, $tenant, $PAGE->url, $context);
    $viewpromptstable->out(100, false);
    $PAGE->requires->js_call_amd('local_ai_manager/viewprompts', 'init', ['local_ai_manager-viewprompts']);

    echo html_writer::end_div();
    echo $OUTPUT->footer();
}

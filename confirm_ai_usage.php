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

use local_ai_manager\form\confirm_ai_usage_form;

require_once(dirname(__FILE__) . '/../../config.php');
require_login();

global $CFG, $DB, $OUTPUT, $PAGE, $USER;

$PAGE->add_body_class('limitcontentwidth');

$url = new moodle_url('/local/ai_manager/confirm_ai_usage.php');

$tenant = \core\di::get(\local_ai_manager\local\tenant::class);

$accessmanager = \core\di::get(\local_ai_manager\local\access_manager::class);
$accessmanager->require_tenant_member();

$PAGE->set_url($url);
$PAGE->set_context($tenant->get_context());
$PAGE->set_pagelayout('admin');

$strtitle = get_string('confirmaitoolsusage_heading', 'local_ai_manager');
$PAGE->set_title($strtitle);
$PAGE->navbar->add($strtitle);
$PAGE->set_secondary_navigation(false);

/** @var \local_ai_manager\local\config_manager $configmanager */
$configmanager = \core\di::get(\local_ai_manager\local\config_manager::class);
$userinfo = new \local_ai_manager\local\userinfo($USER->id);

$legalroles = get_config('local_ai_manager', 'legalroles');
if (empty($legalroles)) {
    $userhaslegalrole = false;
} else {
    $legalroles = array_filter(array_map('trim', explode(',', $legalroles)), 'is_numeric');
    // If the user has at least one of the defined roles he/she will have to consent the data processing.
    $userhaslegalrole = false;
    $systemcontextid = \context_system::instance()->id;
    foreach ($legalroles as $roleid) {
        if (!empty($roleid) && user_has_role_assignment($USER->id, $roleid, $systemcontextid)) {
            $userhaslegalrole = true;
            break;
        }
    }
}
$dataprocessing = get_config('local_ai_manager', 'dataprocessing') ?: '';
$showdataprocessing = $userhaslegalrole && !empty($dataprocessing);

$termsofuse = get_config('local_ai_manager', 'termsofuse') ?: '';
$termsofuselegal = get_config('local_ai_manager', 'termsofuselegal') ?: '';
if (!empty($termsofuselegal) && $userhaslegalrole) {
    $termsofuse = $termsofuselegal;
}
$showtermsofuse = !empty($termsofuse);
$confirmaiusageform =
        new confirm_ai_usage_form(null, ['showtermsofuse' => $showtermsofuse, 'showdataprocessing' => $showdataprocessing]);

if ($confirmaiusageform->is_cancelled()) {
    redirect('/my');
} else if ($data = $confirmaiusageform->get_data()) {
    if (!empty($data->confirmtou)) {
        if (property_exists($data, 'dataprocessing')) {
            $userinfo->set_confirmed(!empty($data->dataprocessing));
        } else {
            $userinfo->set_confirmed(true);
        }
    } else {
        $userinfo->set_confirmed(false);
    }

    $userinfo->store();
    redirect($PAGE->url, get_string('confirmationstatuschanged', 'local_ai_manager'));
}

echo $OUTPUT->header();

$templatecontext['showtermsofuse'] = $showtermsofuse;
if ($showtermsofuse) {
    $templatecontext['termsofuse'] = $termsofuse;
}
$templatecontext['showdataprocessing'] = $showdataprocessing;
if ($showdataprocessing) {
    $templatecontext['dataprocessing'] = $dataprocessing;
}

echo $OUTPUT->render_from_template('local_ai_manager/confirm_ai_usage', $templatecontext);

$confirmaiusageform->set_data(['dataprocessing' => $userinfo->is_confirmed(), 'confirmtou' => $userinfo->is_confirmed()]);
$confirmaiusageform->display();

echo $OUTPUT->footer();

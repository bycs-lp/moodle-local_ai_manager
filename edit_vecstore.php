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
 * Page for adding/editing/deleting a vector database connection instance.
 *
 * @package    local_ai_manager
 * @copyright  2026 Exputo Inc.
 * @author     David Pesce <david.pesce@exputo.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_ai_manager\local\tenant;
use local_ai_manager\local\vecstore_factory;

require_once(dirname(__FILE__) . '/../../config.php');
require_login();

global $CFG, $DB, $PAGE, $OUTPUT, $USER;

$vecstorename = optional_param('vecstorename', '', PARAM_TEXT);
$id = optional_param('id', 0, PARAM_INT);
$del = optional_param('del', 0, PARAM_INT);

\local_ai_manager\local\tenant_config_output_utils::setup_tenant_config_page(new moodle_url('/local/ai_manager/edit_vecstore.php'));

$factory = new vecstore_factory();
$tenant = \core\di::get(tenant::class);
$returnurl = new moodle_url('/local/ai_manager/tenant_config.php', ['tenant' => $tenant->get_identifier()]);
$accessmanager = \core\di::get(\local_ai_manager\local\access_manager::class);

if (!empty($del)) {
    if (empty($id)) {
        throw new moodle_exception('exception_instanceidmissing', 'local_ai_manager');
    }
    require_sesskey();

    $instance = $factory->get_vecstore_instance_by_id($id);
    if ($instance) {
        $tenant = new tenant($instance->get_tenant());
        \core\di::set(tenant::class, $tenant);
        $returnurl = new moodle_url('/local/ai_manager/tenant_config.php', ['tenant' => $tenant->get_identifier()]);
    }
    if (!$accessmanager->can_manage_vecstoreinstance($instance)) {
        throw new moodle_exception('exception_editinstancedenied', 'local_ai_manager');
    }
    $instance->delete();

    redirect($returnurl, get_string('vecstoredeleted', 'local_ai_manager'));
}

if (!empty($id)) {
    $vecstoreinstance = $factory->get_vecstore_instance_by_id($id);
    if (!$accessmanager->can_manage_vecstoreinstance($vecstoreinstance) || !$vecstoreinstance->is_enabled()) {
        throw new moodle_exception('exception_editinstancedenied', 'local_ai_manager');
    }
    $vecstorename = $vecstoreinstance->get_vecstore();
} else {
    if (empty($vecstorename) || !in_array($vecstorename, \local_ai_manager\plugininfo\aivecstore::get_enabled_plugins())) {
        throw new moodle_exception('exception_novalidvecstore', 'local_ai_manager');
    }
    $vecstoreinstance = $factory->get_new_instance($vecstorename);
}

$editvecstoreform = new \local_ai_manager\form\edit_vecstore_form(
    new moodle_url(
        '/local/ai_manager/edit_vecstore.php',
        ['id' => $id, 'vecstorename' => $vecstorename]
    ),
    ['id' => $id, 'tenant' => $tenant->get_identifier(), 'vecstore' => $vecstorename]
);

// Standard form processing if statement.
if ($editvecstoreform->is_cancelled()) {
    redirect($returnurl);
} else if ($data = $editvecstoreform->get_data()) {
    $vecstoreinstance->store_formdata($data);
    redirect(
        new moodle_url(
            '/local/ai_manager/tenant_config.php',
            ['tenant' => $tenant->get_identifier()]
        ),
        get_string('vecstoresaved', 'local_ai_manager'),
        ''
    );
} else {
    echo $OUTPUT->header();
    echo html_writer::start_div('w-75 d-flex flex-column align-items-center ml-auto mr-auto');
    echo $OUTPUT->render_from_template(
        'local_ai_manager/edit_instance_heading',
        [
            'heading' => $OUTPUT->heading(get_string('configurevecstore', 'local_ai_manager')),
            'showdeletebutton' => !empty($id),
            'deleteconfirmstr' => 'vecstoredeleteconfirm',
            'deleteurl' => new moodle_url(
                '/local/ai_manager/edit_vecstore.php',
                ['id' => $id, 'del' => 1, 'sesskey' => sesskey()]
            ),
        ]
    );
    $editvecstoreform->set_data($vecstoreinstance->get_formdata());
    echo html_writer::start_div('w-100');
    $editvecstoreform->display();
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo $OUTPUT->footer();
}

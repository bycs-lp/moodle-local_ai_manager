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
 * Admin page for managing AI model definitions.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_ai_manager\table\model_management_table;

require_once(dirname(__FILE__) . '/../../../config.php');
global $OUTPUT, $PAGE;

require_login();
$context = context_system::instance();
require_capability('local/ai_manager:managemodels', $context);

$url = new moodle_url('/local/ai_manager/manage_models.php');
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');

$strtitle = get_string('manage_models', 'local_ai_manager');
$PAGE->set_title($strtitle);
$PAGE->set_heading($strtitle);

echo $OUTPUT->header();
echo $OUTPUT->heading($strtitle);

// Add model button.
echo html_writer::div(
    html_writer::tag(
        'button',
        $OUTPUT->pix_icon('t/add', '') . ' ' . get_string('model_add', 'local_ai_manager'),
        [
            'type' => 'button',
            'class' => 'btn btn-primary',
            'data-action' => 'addmodel',
        ]
    ),
    'd-flex justify-content-end mb-2'
);

// Render the filter UI.
$uniqid = 'model-management-table-' . uniqid();
$filterrenderable = new \local_ai_manager\output\model_management_table_filter($context, $uniqid);
$templatecontext = $filterrenderable->export_for_template($OUTPUT);
echo $OUTPUT->render_from_template('local_ai_manager/table_filter', $templatecontext);

// Render the dynamic table.
$table = new model_management_table($uniqid);
$table->out(30, false);

$PAGE->requires->js_call_amd('local_ai_manager/model_management', 'init');

echo $OUTPUT->footer();

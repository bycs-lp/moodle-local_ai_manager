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
 * Allows the admin to get information about the AIS API.
 *
 * @package    aitool_telli
 * @copyright  2025 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use aitool_telli\form\management_form;
use aitool_telli\local\utils;
use core\http_client;
use core\output\html_writer;
use Psr\Http\Client\ClientExceptionInterface;

require_once(__DIR__ . '/../../../../config.php');

require_admin();

$PAGE->set_context(context_system::instance());

// Execute the controller.
$PAGE->set_heading(get_string('subpluginspageheading', 'local_ai_manager'));
$PAGE->set_pagelayout('admin');
$PAGE->set_url('/local/ai_manager/tools/telli/management.php');

$managementform = new management_form(null);

if ($managementform->is_cancelled()) {
    redirect(new moodle_url('/admin/settings.php', ['section' => 'aitoolpluginsmanagement']));
} else if ($data = $managementform->get_data()) {
    $apiinfo = utils::get_api_info(
        optional_param('apikey', '', PARAM_TEXT),
        optional_param('baseurl', '', PARAM_URL)
    );

    echo $OUTPUT->header();

    $managementform->display();

    echo html_writer::tag('hr', '', ['class' => 'mt-5 mb-5']);

    echo $OUTPUT->render_from_template(
        'aitool_telli/management',
        [
            'usagejson' => json_encode(
                json_decode($apiinfo->usage),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'modelsjson' => json_encode(
                json_decode($apiinfo->models),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
        ]
    );

    echo $OUTPUT->footer();
} else {
    echo $OUTPUT->header();
    $managementform->display();
    echo $OUTPUT->footer();
}

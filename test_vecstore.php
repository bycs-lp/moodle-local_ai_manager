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
 * Tests connectivity of a configured vector database by calling the backend driver.
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

global $CFG, $PAGE, $OUTPUT;

$id = required_param('id', PARAM_INT);
require_sesskey();

\local_ai_manager\local\tenant_config_output_utils::setup_tenant_config_page(new moodle_url('/local/ai_manager/test_vecstore.php'));

$factory = new vecstore_factory();
$accessmanager = \core\di::get(\local_ai_manager\local\access_manager::class);

$instance = $factory->get_vecstore_instance_by_id($id);
$tenant = new tenant($instance->get_tenant());
\core\di::set(tenant::class, $tenant);
$returnurl = new moodle_url('/local/ai_manager/tenant_config.php', ['tenant' => $tenant->get_identifier()]);

if (!$accessmanager->can_manage_vecstoreinstance($instance)) {
    throw new moodle_exception('exception_editinstancedenied', 'local_ai_manager');
}

$available = $factory->get_vecstore_by_instance($instance)->is_available();

redirect(
    $returnurl,
    $available
        ? get_string('vecstoreconnectionok', 'local_ai_manager', $instance->get_name())
        : get_string('vecstoreconnectionfailed', 'local_ai_manager', $instance->get_name()),
    null,
    $available ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR
);

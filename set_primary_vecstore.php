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
 * Marks a vector database available for the current tenant as its primary one.
 *
 * @package    local_ai_manager
 * @copyright  2026 Exputo Inc.
 * @author     David Pesce <david.pesce@exputo.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_ai_manager\base_vecstore_instance;
use local_ai_manager\local\tenant_factory;
use local_ai_manager\local\vecstore_factory;

require_once(dirname(__FILE__) . '/../../config.php');
require_login();

$id = required_param('id', PARAM_INT);
require_sesskey();

\local_ai_manager\local\tenant_config_output_utils::setup_tenant_config_page(
    new moodle_url('/local/ai_manager/set_primary_vecstore.php')
);

$tenant = \core\di::get(tenant_factory::class)->get();
$returnurl = new moodle_url('/local/ai_manager/tenant_config.php', ['tenant' => $tenant->get_identifier()]);

// Only the enabled vector stores available for the tenant (own and global ones) can become its primary vector store.
$instances = array_filter(
    base_vecstore_instance::get_all_instances(),
    fn(base_vecstore_instance $instance) => $instance->get_id() === $id && $instance->is_enabled()
);
if (empty($instances)) {
    throw new moodle_exception('exception_editinstancedenied', 'local_ai_manager');
}
$instance = reset($instances);

// Sources already indexed in the previous primary vector store stay there, so there is nothing to confirm.
\core\di::get(vecstore_factory::class)->set_primary($instance->get_id());
redirect(
    $returnurl,
    get_string('vecstoreprimaryset', 'local_ai_manager', format_string($instance->get_name())),
    null,
    \core\output\notification::NOTIFY_SUCCESS
);

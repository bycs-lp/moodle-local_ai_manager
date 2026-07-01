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
use local_ai_manager\base_vecstore_instance;
use local_ai_manager\local\tenant;
use local_ai_manager\local\vecstore_factory;
use local_ai_manager\plugininfo\aivecstore;
use moodle_url;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * Vector database table widget shown on the tenant_config.php page.
 *
 * @package    local_ai_manager
 * @copyright  2026 Exputo Inc.
 * @author     David Pesce <david.pesce@exputo.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class vecstoretable implements renderable, templatable {
    #[\Override]
    public function export_for_template(renderer_base $output): stdClass {
        $tenant = \core\di::get(tenant::class);

        // Build the "add" menu from the enabled vector store backends.
        $addoptions = [];
        foreach (aivecstore::get_enabled_plugins() as $vecstorename) {
            $addoptions[] = [
                'label' => get_string('pluginname', 'aivecstore_' . $vecstorename),
                'addurl' => (new moodle_url(
                    '/local/ai_manager/edit_vecstore.php',
                    ['vecstorename' => $vecstorename, 'tenant' => $tenant->get_identifier()]
                ))->out(false),
            ];
        }

        $factory = new vecstore_factory();
        $primaryinstance = $factory->get_primary_instance();
        $primaryid = $primaryinstance ? $primaryinstance->get_id() : 0;

        $vecstores = [];
        foreach (base_vecstore_instance::get_all_instances() as $instance) {
            $isprimary = $instance->get_id() === $primaryid;
            $linkedname = $instance->is_enabled()
                ? html_writer::link(
                    new moodle_url(
                        '/local/ai_manager/edit_vecstore.php',
                        ['id' => $instance->get_id(), 'tenant' => $tenant->get_identifier()]
                    ),
                    $instance->get_name()
                )
                : $instance->get_name();

            $vecstores[] = [
                'name' => $linkedname,
                'backendname' => get_string('pluginname', 'aivecstore_' . $instance->get_vecstore()),
                'endpoint' => $instance->get_endpoint(),
                'collection' => $instance->get_collection(),
                'dimensions' => $instance->get_dimensions(),
                'distance' => get_string('distance_' . $instance->get_distancemetric(), 'local_ai_manager'),
                'enabled' => $instance->is_enabled(),
                'isprimary' => $isprimary,
                'testurl' => $instance->is_enabled()
                    ? (new moodle_url(
                        '/local/ai_manager/test_vecstore.php',
                        ['id' => $instance->get_id(), 'sesskey' => sesskey()]
                    ))->out(false)
                    : null,
                'setprimaryurl' => (!$isprimary && $instance->is_enabled())
                    ? (new moodle_url(
                        '/local/ai_manager/set_primary_vecstore.php',
                        ['id' => $instance->get_id(), 'sesskey' => sesskey()]
                    ))->out(false)
                    : null,
            ];
        }

        return (object) [
            'tenant' => $tenant->get_identifier(),
            'hasbackends' => !empty($addoptions),
            'addoptions' => $addoptions,
            'vecstores' => $vecstores,
        ];
    }
}

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

namespace aipurpose_itt;

use core_plugin_manager;
use local_ai_manager\base_purpose;
use local_ai_manager\local\config_manager;
use local_ai_manager\local\connector_factory;
use local_ai_manager\local\userinfo;

/**
 * Tests for itt purpose.
 *
 * @package   aipurpose_itt
 * @copyright 2025 ISB Bayern
 * @author    Philipp Memmel
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class purpose_test extends \advanced_testcase {
    /**
     * Makes sure that all connector plugins that declare themselves compatible with the itt purpose also define allowed mimetypes.
     *
     * @covers \aipurpose_itt\purpose::get_allowed_mimetypes
     * @covers \local_ai_manager\base_connector::allowed_mimetypes
     */
    public function test_get_allowed_mimetypes(): void {
        $this->resetAfterTest();
        $connectorfactory = \core\di::get(connector_factory::class);
        foreach (array_keys(core_plugin_manager::instance()->get_installed_plugins('aitool')) as $aitool) {
            $newconnector = $connectorfactory->get_connector_by_connectorname($aitool);
            if (!empty($newconnector->get_models_by_purpose()['itt'])) {
                // Some connectors rely on a really existing instance, so we create one.
                $newinstance = $connectorfactory->get_new_instance($aitool);
                $newinstance->set_name('Test instance');
                $newinstance->set_endpoint('https://example.com');
                $newinstance->store();

                $empty = true;
                // We check that the connector returns at least for one of the models a non-empty list of allowed mimetypes.
                foreach ($newconnector->get_models_by_purpose()['itt'] as $model) {
                    $newinstance->set_model($model);
                    $newinstance->store();
                    $configmanager = \core\di::get(config_manager::class);
                    $configmanager->set_config(
                        base_purpose::get_purpose_tool_config_key('itt', userinfo::ROLE_BASIC),
                        $newinstance->get_id()
                    );
                    $connector = $connectorfactory->get_connector_by_purpose('itt', userinfo::ROLE_BASIC);
                    if (!empty($connector->allowed_mimetypes())) {
                        $empty = false;
                        break;
                    }
                }
                $this->assertFalse($empty);
            }
        }
    }
}

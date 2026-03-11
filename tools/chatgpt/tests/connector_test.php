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

namespace aitool_chatgpt;

use local_ai_manager\local\aitool_option_azure;
use local_ai_manager\local\connector_factory;

/**
 * Tests for ChatGPT connector.
 *
 * @package   aitool_chatgpt
 * @copyright 2026 ISB Bayern
 * @author    Philipp Memmel
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class connector_test extends \advanced_testcase {
    /**
     * Test that the Azure model is available for every supported purpose.
     *
     * @throws \coding_exception
     * @covers \aitool_chatgpt\connector::get_models_by_purpose
     */
    public function test_get_models_by_purpose_contains_azure_model(): void {
        $connectorfactory = \core\di::get(connector_factory::class);
        $connector = $connectorfactory->get_connector_by_connectorname('chatgpt');
        $modelname = aitool_option_azure::get_azure_model_name('chatgpt');

        foreach ($connector->get_models_by_purpose() as $models) {
            $this->assertContains($modelname, $models);
        }
    }
}

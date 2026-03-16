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

namespace aitool_dalle;

use local_ai_manager\local\aitool_option_azure;

/**
 * Tests for the aitool_dalle instance.
 *
 * @package    aitool_dalle
 * @copyright  2026 ISB Bayern
 * @author     Thomas Schönlein
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \aitool_dalle\instance
 */
final class instance_test extends \advanced_testcase {
    public function test_get_extended_formdata_maps_azure_model_to_selectable_model(): void {
        $instance = $this->getMockBuilder(instance::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_customfield2', 'azure_enabled'])
            ->getMock();
        $instance->method('get_customfield2')->willReturn('1');
        $instance->method('azure_enabled')->willReturn(true);

        $data = (new \ReflectionMethod(instance::class, 'get_extended_formdata'))->invoke($instance);
        $selectablemodels = \core\di::get(connector::class)->get_selectable_models();

        $this->assertContains($data->model, $selectablemodels);
        $this->assertNotSame(aitool_option_azure::get_azure_model_name('dalle'), $data->model);
    }
}

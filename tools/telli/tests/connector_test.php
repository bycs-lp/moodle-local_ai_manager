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

namespace aitool_telli;

use local_ai_manager\local\connector_factory;
use local_ai_manager\local\unit;

/**
 * Tests for Telli
 *
 * @package   aitool_telli
 * @copyright 2025 ISB Bayern
 * @author    Philipp Memmel
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class connector_test extends \advanced_testcase {
    /**
     * Test the constructor.
     *
     * @covers \aitool_telli\connector::__construct
     */
    public function test_constructor(): void {
        $connectorfactory = \core\di::get(connector_factory::class);
        $connector = $connectorfactory->get_connector_by_connectorname_and_model('telli', 'gpt-4o');
        // Assert that the connector is properly set up by acquiring some information that is being fetched from the
        // wrapped connector.
        $this->assertEquals(unit::TOKEN, $connector->get_unit());

        // Initialize the connector without specifying a model.
        $connector = $connectorfactory->get_connector_by_connectorname('telli');
        // If we come to here, it at least worked, which is what we want to test.
        // Accessing methods that require the wrapped connector to be initialized will fail, though.
        $this->expectException(\Error::class);
        $this->expectExceptionMessage(
            'Typed property aitool_telli\connector::$wrappedconnector must not be accessed before initialization'
        );
        $connector->get_unit();
    }
}

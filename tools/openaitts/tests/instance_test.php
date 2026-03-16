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

namespace aitool_openaitts;

/**
 * Tests for the aitool_openaitts instance.
 *
 * @package    aitool_openaitts
 * @copyright  2026 ISB Bayern
 * @author     Thomas Schönlein
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \aitool_openaitts\instance
 */
final class instance_test extends \advanced_testcase {
    public function test_extend_validation_requires_endpoint_for_azure(): void {
        $instance = new instance();
        $errors = (new \ReflectionMethod(instance::class, 'extend_validation'))->invoke(
            $instance,
            [
                'azure_enabled' => 1,
                'endpoint' => '',
            ],
            []
        );

        $this->assertArrayHasKey('endpoint', $errors);
    }
}

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

namespace local_ai_manager\local;

/**
 * Tests for the Azure option helper.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Thomas Schönlein
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_ai_manager\local\aitool_option_azure
 */
final class aitool_option_azure_test extends \advanced_testcase {
    public function test_validate_azure_options_requires_endpoint_when_enabled(): void {
        $errors = aitool_option_azure::validate_azure_options([
            'azure_enabled' => 1,
            'endpoint' => '',
        ]);

        $this->assertArrayHasKey('endpoint', $errors);
    }

    public function test_validate_azure_options_allows_empty_endpoint_when_disabled(): void {
        $errors = aitool_option_azure::validate_azure_options([
            'azure_enabled' => 0,
            'endpoint' => '',
        ]);

        $this->assertSame([], $errors);
    }
}

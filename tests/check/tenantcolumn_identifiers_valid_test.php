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

namespace local_ai_manager\check;

use core\check\result;
use local_ai_manager\local\tenant;

/**
 * Tests for the tenantcolumn_identifiers_valid check.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(tenantcolumn_identifiers_valid::class)]
final class tenantcolumn_identifiers_valid_test extends \advanced_testcase {
    /**
     * Tests that users must neither have invalid tenant identifiers nor belong to the template tenant.
     */
    public function test_find_invalid_columns(): void {
        $this->resetAfterTest();
        $tenantfield = get_config('local_ai_manager', 'tenantcolumn');
        $this->getDataGenerator()->create_user([$tenantfield => 'tenanta']);
        $this->getDataGenerator()->create_user([$tenantfield => '']);
        $check = new tenantcolumn_identifiers_valid();
        $this->assertSame([], $check->find_invalid_columns());
        $this->assertSame(result::OK, $check->get_result()->get_status());

        $this->getDataGenerator()->create_user([$tenantfield => 'invalid!']);
        $this->getDataGenerator()->create_user([$tenantfield => tenant::TEMPLATE_IDENTIFIER]);
        $this->assertEqualsCanonicalizing(['invalid!', tenant::TEMPLATE_IDENTIFIER], $check->find_invalid_columns());
        $this->assertSame(result::ERROR, $check->get_result()->get_status());
    }
}

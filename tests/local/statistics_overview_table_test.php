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
 * Tests for the statistics overview table.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class statistics_overview_table_test extends \advanced_testcase {
    /**
     * Tests that the model info returned by the AI endpoint is escaped before being rendered.
     *
     * @covers \local_ai_manager\local\statistics_overview_table::col_modelinfo
     */
    public function test_col_modelinfo_escapes_html(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $table = new statistics_overview_table(
            'statistics-overview-table',
            new \moodle_url('/local/ai_manager/statistics.php')
        );

        $payload = '<script src="https://attacker.example/x.js"></script>';
        $result = $table->col_modelinfo((object) ['modelinfo' => $payload]);
        $this->assertStringNotContainsString('<script', $result);
        $this->assertEquals(s($payload), $result);

        $this->assertEquals('gpt-4o-2024-08-06', $table->col_modelinfo((object) ['modelinfo' => 'gpt-4o-2024-08-06']));
        $this->assertEquals('', $table->col_modelinfo((object) ['modelinfo' => null]));
    }
}

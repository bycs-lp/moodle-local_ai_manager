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
#[\PHPUnit\Framework\Attributes\CoversClass(statistics_overview_table::class)]
final class statistics_overview_table_test extends \advanced_testcase {
    /**
     * Tests that the table SQL runs on all supported databases and aggregates the request log correctly.
     */
    public function test_query_db_aggregates_request_log(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $tenant = new tenant('tenant1');
        \core\di::get(tenant_factory::class)->set($tenant);

        /** @var \local_ai_manager_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_ai_manager');
        $generator->create_request_log_entry(['tenant' => $tenant->get_sql_identifier(), 'value' => 100]);
        $generator->create_request_log_entry(['tenant' => $tenant->get_sql_identifier(), 'value' => 50]);
        $generator->create_request_log_entry([
            'tenant' => $tenant->get_sql_identifier(),
            'connector' => 'gemini',
            'model' => 'gemini-2.0-flash',
            'modelinfo' => 'gemini-2.0-flash-001',
            'value' => 10,
        ]);
        // Entry of another tenant must not be included.
        $generator->create_request_log_entry(['tenant' => 'tenant2']);

        $table = new statistics_overview_table(
            'statistics-overview-table',
            new \moodle_url('/local/ai_manager/statistics.php')
        );
        $table->query_db(30, false);

        $this->assertCount(2, $table->rawdata);
        $chatgptrow = $table->rawdata['gpt-4o-2024-08-06-gpt-4o-chatgpt'];
        $this->assertEquals(2, $chatgptrow->requestcount);
        $this->assertEquals(150, $chatgptrow->userusage);
        $geminirow = $table->rawdata['gemini-2.0-flash-001-gemini-2.0-flash-gemini'];
        $this->assertEquals(1, $geminirow->requestcount);
        $this->assertEquals(10, $geminirow->userusage);
    }

    /**
     * Tests that the model info returned by the AI endpoint is escaped before being rendered.
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

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

namespace local_ai_manager;

use local_ai_manager\plugininfo\aitool;
use advanced_testcase;

/**
 * Test class for the pluginfo/aitool functions.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Johannes Funk
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class pluginfo_aitool_test extends advanced_testcase {
    /**
     * Tests the method unistall
     *
     * @covers \local_ai_manager\plugininfo\aitool::uninstall
     */
    public function test_uninstall(): void {
        global $DB;

        $this->resetAfterTest();

        $connectorname = "testconnector";

        $instance = [
            'connector' => $connectorname,
        ];
        $id1 = $DB->insert_record('local_ai_manager_instance', (object)$instance);
        $id2 = $DB->insert_record('local_ai_manager_instance', (object)$instance);

        // Generate three example configs: one to be deleted, two to be kept.
        $DB->insert_record('local_ai_manager_config', (object)[
            'configkey' => 'purpose_somepurpose_tool_role_somerole',
            'configvalue' => $id1,
        ]);

        $DB->insert_record('local_ai_manager_config', (object)[
            'configkey' => 'another_configkey',
            'configvalue' => $id2,
        ]);
        $otherid = $id1 + $id2;
        $DB->insert_record('local_ai_manager_config', (object)[
            'configkey' => 'purpose_somepurpose_tool_role_somerole',
            'configvalue' => $otherid,
        ]);

        $pluginfo = new aitool();
        $pluginfo->name = $connectorname;

        $result = $pluginfo->uninstall(new \null_progress_trace());
        $this->assertTrue($result);

        // No more instances of this connector.
        $instancecount = $DB->count_records('local_ai_manager_instance', ['connector' => $connectorname]);
        $this->assertEquals(0, $instancecount);

        $this->assertEquals(0, $DB->count_records('local_ai_manager_config', [
            'configkey'   => 'purpose_somepurpose_tool_role_somerole',
            'configvalue' => (string)$id1,
        ]));

        $this->assertEquals(1, $DB->count_records('local_ai_manager_config', [
            'configkey' => 'another_configkey',
            'configvalue' => (string)$id2,
        ]));

        $this->assertEquals(1, $DB->count_records('local_ai_manager_config', [
            'configkey'   => 'purpose_somepurpose_tool_role_somerole',
            'configvalue' => (string)$otherid,
        ]));
    }
}

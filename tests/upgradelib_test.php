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

/**
 * Tests for local_ai_manager upgrade helpers.
 *
 * @package   local_ai_manager
 * @copyright 2026 ISB Bayern
 * @author    Thomas Schönlein
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class upgradelib_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/local/ai_manager/db/upgradelib.php');
    }

    public function test_cleanup_legacy_azure_instance_data_keeps_gemini_customfield3(): void {
        global $DB;
        $this->resetAfterTest();

        $chatgptid = $DB->insert_record('local_ai_manager_instance', (object) [
            'name' => 'ChatGPT Azure',
            'tenant' => 'tenant1',
            'connector' => 'chatgpt',
            'endpoint' => null,
            'apikey' => null,
            'useglobalapikey' => 0,
            'model' => 'chatgpt_preconfigured_azure',
            'infolink' => null,
            'customfield1' => null,
            'customfield2' => '1',
            'customfield3' => 'old-resource',
            'customfield4' => 'old-deployment',
            'customfield5' => '2024-02-01',
            'timecreated' => 0,
            'timemodified' => 0,
        ]);

        $geminijson = '{"project_id":"gemini-project"}';
        $geminiid = $DB->insert_record('local_ai_manager_instance', (object) [
            'name' => 'Gemini Vertex',
            'tenant' => 'tenant1',
            'connector' => 'gemini',
            'endpoint' => null,
            'apikey' => null,
            'useglobalapikey' => 0,
            'model' => 'gemini-2.0-flash',
            'infolink' => null,
            'customfield1' => null,
            'customfield2' => 'vertexai',
            'customfield3' => $geminijson,
            'customfield4' => 'old-deployment',
            'customfield5' => '2024-02-01',
            'timecreated' => 0,
            'timemodified' => 0,
        ]);

        \local_ai_manager_cleanup_legacy_azure_instance_data();

        $chatgptrecord = $DB->get_record('local_ai_manager_instance', ['id' => $chatgptid], '*', MUST_EXIST);
        $geminirecord = $DB->get_record('local_ai_manager_instance', ['id' => $geminiid], '*', MUST_EXIST);

        $this->assertNull($chatgptrecord->customfield3);
        $this->assertNull($chatgptrecord->customfield4);
        $this->assertNull($chatgptrecord->customfield5);

        $this->assertSame($geminijson, $geminirecord->customfield3);
        $this->assertNull($geminirecord->customfield4);
        $this->assertNull($geminirecord->customfield5);
    }
}

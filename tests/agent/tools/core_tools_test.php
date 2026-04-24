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

/**
 * Unit tests for the core agent tools (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent\tools;

use local_ai_manager\agent\execution_context;
use local_ai_manager\agent\tool_registry;
use local_ai_manager\agent\tools\course\course_get_info;
use local_ai_manager\agent\tools\course\course_list;
use local_ai_manager\agent\tools\course\course_section_update_summary;

/**
 * Tests for the base tool contract and the three initial course tools.
 *
 * @covers \local_ai_manager\agent\tools\base_tool
 * @covers \local_ai_manager\agent\tools\course\course_list
 * @covers \local_ai_manager\agent\tools\course\course_get_info
 * @covers \local_ai_manager\agent\tools\course\course_section_update_summary
 */
final class core_tools_test extends \advanced_testcase {

    /**
     * Build a minimal execution_context for a given user + context.
     *
     * @param \stdClass $user
     * @param \core\context $ctx
     * @return execution_context
     */
    private function build_exec_context(\stdClass $user, \core\context $ctx): execution_context {
        return new execution_context(
            runid: 1,
            callid: 1,
            callindex: 0,
            user: $user,
            context: $ctx,
            tenantid: null,
            draftitemids: [],
            entity_context: [],
            clock: \core\di::get(\core\clock::class),
        );
    }

    /**
     * Every core tool must pass the metadata linter (description contract + schema).
     */
    public function test_metadata_contract_for_all_core_tools(): void {
        $this->resetAfterTest();
        $registry = new tool_registry();
        foreach ([new course_list(), new course_get_info(), new course_section_update_summary()] as $tool) {
            $warnings = $registry->validate_metadata($tool);
            $this->assertSame([], $warnings, "Tool '{$tool->get_name()}' produced warnings: " . implode(', ', $warnings));
            $this->assertGreaterThanOrEqual(200, strlen($tool->get_description()));
            $this->assertStringContainsStringIgnoringCase('use this tool when', $tool->get_description());
            $this->assertStringContainsStringIgnoringCase('do not use', $tool->get_description());
        }
    }

    /**
     * course_list returns enrolled, visible courses.
     */
    public function test_course_list_returns_enrolled_courses(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();

        $user = $gen->create_user();
        $c1 = $gen->create_course(['shortname' => 'C1', 'fullname' => 'Course One']);
        $c2 = $gen->create_course(['shortname' => 'C2', 'fullname' => 'Course Two']);
        $gen->enrol_user($user->id, $c1->id, 'student');
        $gen->enrol_user($user->id, $c2->id, 'student');
        $this->setUser($user);

        $tool = new course_list();
        $result = $tool->execute(['limit' => 10], $this->build_exec_context($user, \core\context\system::instance()));

        $this->assertTrue($result->ok, 'course_list failed: ' . ($result->error ?? '') . ' / ' . ($result->user_message ?? ''));
        $this->assertIsArray($result->data['courses']);
        $shortnames = array_column($result->data['courses'], 'shortname');
        $this->assertContains('C1', $shortnames);
        $this->assertContains('C2', $shortnames);
    }

    /**
     * course_list obeys the limit argument.
     */
    public function test_course_list_respects_limit(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $user = $gen->create_user();
        for ($i = 1; $i <= 3; $i++) {
            $c = $gen->create_course(['shortname' => 'CL' . $i]);
            $gen->enrol_user($user->id, $c->id, 'student');
        }
        $this->setUser($user);

        $tool = new course_list();
        $result = $tool->execute(['limit' => 2], $this->build_exec_context($user, \core\context\system::instance()));

        $this->assertTrue($result->ok);
        $this->assertCount(2, $result->data['courses']);
    }

    /**
     * course_get_info by id returns metadata and affected_objects.
     */
    public function test_course_get_info_by_id_returns_metadata(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['shortname' => 'XX', 'fullname' => 'Kurs XX', 'numsections' => 3]);
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $course->id, 'editingteacher');
        $this->setUser($user);

        $tool = new course_get_info();
        $result = $tool->execute(['courseid' => (int) $course->id],
            $this->build_exec_context($user, \core\context\course::instance($course->id)));

        $this->assertTrue($result->ok);
        $this->assertSame((int) $course->id, $result->data['id']);
        $this->assertSame('XX', $result->data['shortname']);
        $this->assertGreaterThanOrEqual(3, $result->data['numsections']);
        $this->assertCount(1, $result->affected_objects);
        $this->assertSame('course', $result->affected_objects[0]['type']);
    }

    /**
     * course_get_info fails cleanly when neither argument is given.
     */
    public function test_course_get_info_missing_argument(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $tool = new course_get_info();
        $result = $tool->execute([], $this->build_exec_context($user, \core\context\system::instance()));
        $this->assertFalse($result->ok);
        $this->assertSame('missing_argument', $result->error);
    }

    /**
     * course_get_info fails with course_not_found for unknown shortname.
     */
    public function test_course_get_info_not_found(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $tool = new course_get_info();
        $result = $tool->execute(['shortname' => 'DOES_NOT_EXIST'],
            $this->build_exec_context($user, \core\context\system::instance()));
        $this->assertFalse($result->ok);
        $this->assertSame('course_not_found', $result->error);
    }

    /**
     * course_section_update_summary writes the section and returns a usable undo payload.
     */
    public function test_section_update_summary_writes_and_builds_undo(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['numsections' => 3]);
        $teacher = $gen->create_user();
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        global $DB;
        $DB->set_field('course_sections', 'summary', '<p>old</p>',
            ['course' => $course->id, 'section' => 2]);

        $tool = new course_section_update_summary();
        $ctx = \core\context\course::instance($course->id);
        $args = ['courseid' => (int) $course->id, 'section' => 2, 'summary' => '<p>new text</p>'];
        $result = $tool->execute($args, $this->build_exec_context($teacher, $ctx));

        $this->assertTrue($result->ok, 'execution failed: ' . ($result->error ?? ''));
        $this->assertSame('<p>old</p>', $result->data['previous_summary']);
        $stored = $DB->get_field('course_sections', 'summary',
            ['course' => $course->id, 'section' => 2]);
        $this->assertStringContainsString('new text', (string) $stored);

        $undo = $tool->build_undo_payload($args, $result);
        $this->assertIsArray($undo);
        $this->assertSame('course_section_update_summary', $undo['tool']);
        $this->assertSame('<p>old</p>', $undo['args']['summary']);
    }

    /**
     * Section update refuses execution when the capability is missing.
     */
    public function test_section_update_summary_requires_capability(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['numsections' => 3]);
        $student = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        $tool = new course_section_update_summary();
        $result = $tool->execute(
            ['courseid' => (int) $course->id, 'section' => 1, 'summary' => 'x'],
            $this->build_exec_context($student, \core\context\course::instance($course->id)),
        );

        $this->assertFalse($result->ok);
        $this->assertNotEmpty($result->error);
    }
}

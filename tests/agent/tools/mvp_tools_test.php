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
 * Unit tests for the Paket 6 MVP tools (MBS-10761 SPEZ §4.7).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent\tools;

use local_ai_manager\agent\execution_context;
use local_ai_manager\agent\tool_registry;
use local_ai_manager\agent\tools\course\course_find_by_name;
use local_ai_manager\agent\tools\forum\forum_create_discussion;
use local_ai_manager\agent\tools\question\question_create_multichoice_batch;

/**
 * Tests for course_find_by_name, forum_create_discussion (metadata + error paths)
 * and question_create_multichoice_batch (success + partial failure).
 *
 * @covers \local_ai_manager\agent\tools\course\course_find_by_name
 * @covers \local_ai_manager\agent\tools\forum\forum_create_discussion
 * @covers \local_ai_manager\agent\tools\question\question_create_multichoice_batch
 */
final class mvp_tools_test extends \advanced_testcase {

    /**
     * Build a minimal execution_context for a given user and context.
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
     * All three MVP tools pass the metadata contract linter.
     */
    public function test_metadata_contract_for_mvp_tools(): void {
        $this->resetAfterTest();
        $registry = new tool_registry();
        foreach ([new course_find_by_name(), new forum_create_discussion(), new question_create_multichoice_batch()] as $tool) {
            $warnings = $registry->validate_metadata($tool);
            $this->assertSame([], $warnings, "Tool '{$tool->get_name()}' produced warnings: " . implode(', ', $warnings));
            $this->assertGreaterThanOrEqual(200, strlen($tool->get_description()));
            $this->assertStringContainsStringIgnoringCase('use this tool', $tool->get_description());
            $this->assertStringContainsStringIgnoringCase('do not use', $tool->get_description());
        }
    }

    /**
     * course_find_by_name matches fullname and shortname substrings.
     */
    public function test_course_find_by_name_matches_fullname_and_shortname(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $user = $gen->create_user();
        $c1 = $gen->create_course(['shortname' => 'PHY8', 'fullname' => 'Physik 8a']);
        $c2 = $gen->create_course(['shortname' => 'MATH7', 'fullname' => 'Mathematik 7b']);
        $c3 = $gen->create_course(['shortname' => 'PHY9', 'fullname' => 'Physik 9a']);
        $gen->enrol_user($user->id, $c1->id, 'student');
        $gen->enrol_user($user->id, $c2->id, 'student');
        $gen->enrol_user($user->id, $c3->id, 'student');
        $this->setUser($user);

        $tool = new course_find_by_name();
        $ctx = $this->build_exec_context($user, \core\context\system::instance());

        // Fullname substring.
        $result = $tool->execute(['name' => 'Physik', 'limit' => 10], $ctx);
        $this->assertTrue($result->ok);
        $ids = array_column($result->data['courses'], 'id');
        $this->assertContains((int) $c1->id, $ids);
        $this->assertContains((int) $c3->id, $ids);
        $this->assertNotContains((int) $c2->id, $ids);

        // Shortname substring, case-insensitive.
        $result = $tool->execute(['name' => 'math'], $ctx);
        $this->assertTrue($result->ok);
        $ids = array_column($result->data['courses'], 'id');
        $this->assertContains((int) $c2->id, $ids);

        // Limit honoured.
        $result = $tool->execute(['name' => 'Physik', 'limit' => 1], $ctx);
        $this->assertTrue($result->ok);
        $this->assertCount(1, $result->data['courses']);
    }

    /**
     * course_find_by_name rejects empty input.
     */
    public function test_course_find_by_name_rejects_empty(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $tool = new course_find_by_name();
        $result = $tool->execute(['name' => '   '],
            $this->build_exec_context($user, \core\context\system::instance()));
        $this->assertFalse($result->ok);
        $this->assertSame('invalid_argument', $result->error);
    }

    /**
     * course_find_by_name does not leak courses the user cannot see.
     */
    public function test_course_find_by_name_filters_unauthorised(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $user = $gen->create_user();
        $hidden = $gen->create_course(['shortname' => 'HIDDEN', 'fullname' => 'Hidden Course', 'visible' => 0]);
        // User not enrolled, not admin — must not see the hidden course.
        $this->setUser($user);

        $tool = new course_find_by_name();
        $result = $tool->execute(['name' => 'Hidden'],
            $this->build_exec_context($user, \core\context\system::instance()));
        $this->assertTrue($result->ok);
        $ids = array_column($result->data['courses'], 'id');
        $this->assertNotContains((int) $hidden->id, $ids);
    }

    /**
     * forum_create_discussion fails with forum_not_found for an unknown forum id.
     */
    public function test_forum_create_discussion_forum_not_found(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $tool = new forum_create_discussion();
        $result = $tool->execute(
            ['forumid' => 999999, 'subject' => 'X', 'message' => '<p>Y</p>'],
            $this->build_exec_context($user, \core\context\system::instance())
        );
        $this->assertFalse($result->ok);
        $this->assertSame('forum_not_found', $result->error);
    }

    /**
     * forum_create_discussion refuses single-topic forums.
     */
    public function test_forum_create_discussion_rejects_single_forum(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $teacher = $gen->create_user();
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        $forum = $gen->create_module('forum', ['course' => $course->id, 'type' => 'single']);
        $this->setUser($teacher);

        $tool = new forum_create_discussion();
        $result = $tool->execute(
            ['forumid' => (int) $forum->id, 'subject' => 'X', 'message' => '<p>Y</p>'],
            $this->build_exec_context($teacher, \core\context\course::instance($course->id))
        );
        $this->assertFalse($result->ok);
        $this->assertSame('forum_type_not_allowed', $result->error);
    }

    /**
     * forum_create_discussion creates a discussion in a general forum.
     */
    public function test_forum_create_discussion_creates_discussion(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $teacher = $gen->create_user();
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        $forum = $gen->create_module('forum', ['course' => $course->id, 'type' => 'general']);
        $this->setUser($teacher);

        $tool = new forum_create_discussion();
        $result = $tool->execute(
            [
                'forumid' => (int) $forum->id,
                'subject' => 'Agent subject',
                'message' => '<p>Agent message</p>',
            ],
            $this->build_exec_context($teacher, \core\context\course::instance($course->id))
        );
        $this->assertTrue($result->ok, 'forum_create_discussion failed: ' . ($result->error ?? '') . ' / ' . ($result->user_message ?? ''));
        $this->assertSame((int) $forum->id, $result->data['forumid']);
        $this->assertSame('Agent subject', $result->data['subject']);
        $this->assertGreaterThan(0, $result->data['discussionid']);

        $discussion = $DB->get_record('forum_discussions', ['id' => $result->data['discussionid']], '*', MUST_EXIST);
        $this->assertSame('Agent subject', $discussion->name);

        $undo = $tool->build_undo_payload([], $result);
        $this->assertIsArray($undo);
        $this->assertSame('forum_delete_discussion', $undo['tool']);
        $this->assertSame($result->data['discussionid'], $undo['args']['discussionid']);
    }

    /**
     * question_create_multichoice_batch creates all items when all are valid.
     */
    public function test_question_batch_success(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $coursecat = $this->getDataGenerator()->create_category();
        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $qcat = $qgen->create_question_category(['contextid' => \core\context\coursecat::instance($coursecat->id)->id]);

        $tool = new question_create_multichoice_batch();
        // Use current admin user (setAdminUser) to avoid capability issues.
        global $USER;
        $execctx = new execution_context(
            runid: 1,
            callid: 1,
            callindex: 0,
            user: $USER,
            context: \core\context\system::instance(),
            tenantid: null,
            draftitemids: [],
            entity_context: [],
            clock: \core\di::get(\core\clock::class),
        );

        $args = ['items' => [
            [
                'categoryid' => (int) $qcat->id,
                'name' => 'Q1',
                'questiontext' => '<p>What is 1+1?</p>',
                'single' => true,
                'choices' => [
                    ['text' => '2', 'correct' => true],
                    ['text' => '3', 'correct' => false],
                ],
            ],
            [
                'categoryid' => (int) $qcat->id,
                'name' => 'Q2',
                'questiontext' => '<p>What is 2+2?</p>',
                'single' => true,
                'choices' => [
                    ['text' => '4', 'correct' => true],
                    ['text' => '5', 'correct' => false],
                ],
            ],
        ]];
        $result = $tool->execute($args, $execctx);
        $this->assertTrue($result->ok, 'batch failed: ' . ($result->error ?? ''));
        $this->assertCount(2, $result->data['succeeded']);
        $this->assertCount(0, $result->data['failed']);
        foreach ($result->data['succeeded'] as $s) {
            $this->assertGreaterThan(0, $s['questionid']);
            $this->assertTrue($DB->record_exists('question', ['id' => $s['questionid']]));
        }
    }

    /**
     * question_create_multichoice_batch returns partial success when one item is malformed.
     */
    public function test_question_batch_partial_failure(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $coursecat = $this->getDataGenerator()->create_category();
        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $qcat = $qgen->create_question_category(['contextid' => \core\context\coursecat::instance($coursecat->id)->id]);

        $tool = new question_create_multichoice_batch();
        global $USER;
        $execctx = new execution_context(
            runid: 1,
            callid: 1,
            callindex: 0,
            user: $USER,
            context: \core\context\system::instance(),
            tenantid: null,
            draftitemids: [],
            entity_context: [],
            clock: \core\di::get(\core\clock::class),
        );

        $args = ['items' => [
            // Valid.
            [
                'categoryid' => (int) $qcat->id,
                'name' => 'Good Q',
                'questiontext' => '<p>OK?</p>',
                'single' => true,
                'choices' => [
                    ['text' => 'yes', 'correct' => true],
                    ['text' => 'no', 'correct' => false],
                ],
            ],
            // Invalid — single=true but zero correct.
            [
                'categoryid' => (int) $qcat->id,
                'name' => 'Bad Q',
                'questiontext' => '<p>OK?</p>',
                'single' => true,
                'choices' => [
                    ['text' => 'a', 'correct' => false],
                    ['text' => 'b', 'correct' => false],
                ],
            ],
        ]];
        $result = $tool->execute($args, $execctx);
        // Batch is "ok" if at least one item succeeded.
        $this->assertTrue($result->ok);
        $this->assertCount(1, $result->data['succeeded']);
        $this->assertCount(1, $result->data['failed']);
        $this->assertSame(0, $result->data['succeeded'][0]['input_index']);
        $this->assertSame(1, $result->data['failed'][0]['input_index']);
        $this->assertSame('invalid_qtype_data', $result->data['failed'][0]['error_code']);
    }

    /**
     * question_create_multichoice_batch rejects empty and oversized batches.
     */
    public function test_question_batch_size_limits(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $tool = new question_create_multichoice_batch();
        global $USER;
        $execctx = new execution_context(
            runid: 1,
            callid: 1,
            callindex: 0,
            user: $USER,
            context: \core\context\system::instance(),
            tenantid: null,
            draftitemids: [],
            entity_context: [],
            clock: \core\di::get(\core\clock::class),
        );

        $empty = $tool->execute(['items' => []], $execctx);
        $this->assertFalse($empty->ok);
        $this->assertSame('invalid_argument', $empty->error);

        $items = [];
        for ($i = 0; $i < 51; $i++) {
            $items[] = [
                'categoryid' => 1,
                'name' => 'x' . $i,
                'questiontext' => 'x',
                'choices' => [['text' => 'a', 'correct' => true], ['text' => 'b', 'correct' => false]],
            ];
        }
        $toobig = $tool->execute(['items' => $items], $execctx);
        $this->assertFalse($toobig->ok);
        $this->assertSame('too_many_items', $toobig->error);
    }
}

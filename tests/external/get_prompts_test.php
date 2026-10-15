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

namespace local_ai_manager\external;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests for the get_prompts web service.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(get_prompts::class)]
final class get_prompts_test extends \advanced_testcase {
    #[Group('baseline')]
    /**
     * A teacher cannot read the prompts of a site administrator.
     *
     * @return void
     */
    public function test_prompts_of_admin_are_protected(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $this->expectException(\moodle_exception::class);
        get_prompts::execute(\context_course::instance($course->id)->id, get_admin()->id, 0);
    }

    #[Group('baseline')]
    /**
     * A teacher still reads the prompts of a student.
     *
     * @return void
     */
    public function test_prompts_of_student_are_readable(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($teacher);

        $result = get_prompts::execute(\context_course::instance($course->id)->id, $student->id, 0);

        $this->assertSame(200, $result['code']);
    }
}

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

    /**
     * A teacher cannot read the prompts of a user holding a role configured as privileged role.
     *
     * @return void
     */
    public function test_prompts_of_privileged_role_holder_are_protected(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $roleid = $this->getDataGenerator()->create_role();
        set_config('privilegedroles', (string) $roleid, 'local_ai_manager');
        $privileged = $this->getDataGenerator()->create_and_enrol($course, 'student');
        role_assign($roleid, $privileged->id, SYSCONTEXTID);
        $this->setUser($teacher);
        $coursecontextid = \context_course::instance($course->id)->id;

        $exceptionthrown = false;
        try {
            get_prompts::execute($coursecontextid, $privileged->id, 0);
        } catch (\moodle_exception $exception) {
            $exceptionthrown = true;
            $this->assertNotInstanceOf(\required_capability_exception::class, $exception);
            $this->assertSame('nopermissions', $exception->errorcode);
        }
        $this->assertTrue($exceptionthrown);
    }

    /**
     * A teacher cannot read the prompts of a user holding any of several roles configured as privileged roles.
     *
     * @return void
     */
    public function test_prompts_of_holder_of_second_privileged_role_are_protected(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $firstroleid = $this->getDataGenerator()->create_role();
        $secondroleid = $this->getDataGenerator()->create_role();
        set_config('privilegedroles', $firstroleid . ',' . $secondroleid, 'local_ai_manager');
        $privileged = $this->getDataGenerator()->create_and_enrol($course, 'student');
        role_assign($secondroleid, $privileged->id, SYSCONTEXTID);
        $this->setUser($teacher);
        $coursecontextid = \context_course::instance($course->id)->id;

        $exceptionthrown = false;
        try {
            get_prompts::execute($coursecontextid, $privileged->id, 0);
        } catch (\moodle_exception $exception) {
            $exceptionthrown = true;
            $this->assertNotInstanceOf(\required_capability_exception::class, $exception);
            $this->assertSame('nopermissions', $exception->errorcode);
        }
        $this->assertTrue($exceptionthrown);
    }

    /**
     * A tenant manager cannot read the prompts of a site administrator via the tenant context.
     *
     * @return void
     */
    public function test_prompts_of_admin_are_protected_in_tenant_context(): void {
        $this->resetAfterTest();
        $this->redirectHook(\local_ai_manager\hook\userinfo_extend::class, function () {
            return null;
        });
        $category = $this->getDataGenerator()->create_category();
        $tenantcontext = \context_coursecat::instance($category->id);
        $this->redirectHook(
            \local_ai_manager\hook\custom_tenant::class,
            function (\local_ai_manager\hook\custom_tenant $customtenant) use ($tenantcontext) {
                $customtenant->set_tenant_context($tenantcontext);
            }
        );
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/ai_manager:viewtenantprompts', CAP_ALLOW, $roleid, SYSCONTEXTID);
        $manager = $this->getDataGenerator()->create_user();
        role_assign($roleid, $manager->id, $tenantcontext->id);
        $this->setUser($manager);
        \core\di::reset_container();

        // The manager is allowed to open the tenant prompts, so the denial below is caused by the protected target user.
        $this->assertTrue(has_capability('local/ai_manager:viewtenantprompts', $tenantcontext));
        $student = $this->getDataGenerator()->create_user();
        $result = get_prompts::execute($tenantcontext->id, $student->id, 0);
        $this->assertSame(200, $result['code']);

        $exceptionthrown = false;
        try {
            get_prompts::execute($tenantcontext->id, get_admin()->id, 0);
        } catch (\moodle_exception $exception) {
            $exceptionthrown = true;
            $this->assertNotInstanceOf(\required_capability_exception::class, $exception);
            $this->assertSame('nopermissions', $exception->errorcode);
        }
        $this->assertTrue($exceptionthrown);
    }

    /**
     * A user holding a privileged role can still read their own prompts.
     *
     * @return void
     */
    public function test_privileged_user_reads_own_prompts(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $roleid = $this->getDataGenerator()->create_role();
        set_config('privilegedroles', (string) $roleid, 'local_ai_manager');
        role_assign($roleid, $teacher->id, SYSCONTEXTID);
        $this->setUser($teacher);
        $coursecontextid = \context_course::instance($course->id)->id;

        $result = get_prompts::execute($coursecontextid, $teacher->id, 0);

        $this->assertSame(200, $result['code']);
    }
}

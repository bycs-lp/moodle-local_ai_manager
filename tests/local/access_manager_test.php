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

use local_ai_manager\hook\custom_tenant;

/**
 * Tests for the access_manager class.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_manager\local\access_manager
 */
final class access_manager_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->redirectHook(\local_ai_manager\hook\userinfo_extend::class, fn() => null);
        $categorycontexts = [
            'schoolb' => \context_coursecat::instance($this->getDataGenerator()->create_category()->id),
            'schoolc' => \context_coursecat::instance($this->getDataGenerator()->create_category()->id),
        ];
        // Simulates a plugin like local_bycsauth: Some tenants have their own context, non-existing tenants cause an exception.
        $this->redirectHook(custom_tenant::class, function (custom_tenant $customtenant) use ($categorycontexts) {
            $identifier = $customtenant->get_tenantidentifier();
            if (array_key_exists($identifier, $categorycontexts)) {
                $customtenant->set_tenant_context($categorycontexts[$identifier]);
            } else if (!in_array($identifier, ['schoola', tenant::DEFAULT_IDENTIFIER])) {
                throw new \moodle_exception('Invalid school id');
            }
        });
    }

    /**
     * Creates a user of the given tenant with the given role and logs the user in.
     *
     * @param string $institution the institution field of the user, used as the tenant identifier
     * @param ?string $role null for no additional role, 'managetenants' for managing all tenants or the identifier of a tenant
     *  with its own context the user should get the manager role in
     */
    private function setup_user(string $institution, ?string $role = null): void {
        $user = $this->getDataGenerator()->create_user(['institution' => $institution]);
        $this->setUser($user);
        if (is_null($role)) {
            return;
        }
        $roleid = $this->getDataGenerator()->create_role();
        if ($role === 'managetenants') {
            assign_capability('local/ai_manager:managetenants', CAP_ALLOW, $roleid, SYSCONTEXTID);
            role_assign($roleid, $user->id, SYSCONTEXTID);
        } else {
            assign_capability('local/ai_manager:manage', CAP_ALLOW, $roleid, SYSCONTEXTID);
            role_assign($roleid, $user->id, (new tenant($role))->get_context()->id);
        }
    }

    /**
     * Tests the method require_tenant_access.
     *
     * @param string $institution the tenant of the user
     * @param ?string $role the role of the user, see {@see self::setup_user()}
     * @param string $requestedtenant the tenant the user wants to access
     * @param bool $allowed whether the access should be allowed
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('require_tenant_access_provider')]
    public function test_require_tenant_access(string $institution, ?string $role, string $requestedtenant, bool $allowed): void {
        $this->setup_user($institution, $role);
        if (!$allowed) {
            $this->expectException(\moodle_exception::class);
            $this->expectExceptionMessage(get_string('exception_tenantaccessdenied', 'local_ai_manager', $requestedtenant));
        }
        \core\di::get(access_manager::class)->require_tenant_access($requestedtenant);
    }

    /**
     * Data provider for {@see self::test_require_tenant_access()}.
     *
     * @return array the test cases
     */
    public static function require_tenant_access_provider(): array {
        return [
            'member_own_tenant' => [
                'institution' => 'schoola',
                'role' => null,
                'requestedtenant' => 'schoola',
                'allowed' => true,
            ],
            'member_foreign_tenant' => [
                'institution' => 'schoola',
                'role' => null,
                'requestedtenant' => 'schoolb',
                'allowed' => false,
            ],
            'member_default_tenant' => [
                'institution' => 'schoola',
                'role' => null,
                'requestedtenant' => tenant::DEFAULT_IDENTIFIER,
                'allowed' => false,
            ],
            'user_without_tenant_default_tenant' => [
                'institution' => '',
                'role' => null,
                'requestedtenant' => tenant::DEFAULT_IDENTIFIER,
                'allowed' => true,
            ],
            'user_without_tenant_foreign_tenant' => [
                'institution' => '',
                'role' => null,
                'requestedtenant' => 'schoola',
                'allowed' => false,
            ],
            'managetenants_foreign_tenant' => [
                'institution' => 'schoola',
                'role' => 'managetenants',
                'requestedtenant' => 'schoolb',
                'allowed' => true,
            ],
            'managetenants_default_tenant' => [
                'institution' => 'schoola',
                'role' => 'managetenants',
                'requestedtenant' => tenant::DEFAULT_IDENTIFIER,
                'allowed' => true,
            ],
            'manager_by_tenant_context_managed_tenant' => [
                'institution' => 'schoola',
                'role' => 'schoolb',
                'requestedtenant' => 'schoolb',
                'allowed' => true,
            ],
            'manager_by_tenant_context_other_tenant' => [
                'institution' => 'schoola',
                'role' => 'schoolb',
                'requestedtenant' => 'schoolc',
                'allowed' => false,
            ],
            // Must result in the same exception as a foreign tenant to not reveal which tenants exist.
            'non_existing_tenant' => [
                'institution' => 'schoola',
                'role' => null,
                'requestedtenant' => 'notexisting',
                'allowed' => false,
            ],
            'invalid_tenant_identifier' => [
                'institution' => 'schoola',
                'role' => null,
                'requestedtenant' => ' invalid identifier!',
                'allowed' => false,
            ],
        ];
    }

    /**
     * Tests that a missing tenant context record results in the access denied exception, but database errors are passed through.
     */
    public function test_require_tenant_access_exception_handling(): void {
        $this->setup_user('schoola');
        $accessmanager = \core\di::get(access_manager::class);

        $this->redirectHook(custom_tenant::class, function () {
            throw new \dml_missing_record_exception('context');
        });
        try {
            $accessmanager->require_tenant_access('schoolb');
            $this->fail('A missing tenant context must result in an exception');
        } catch (\moodle_exception $exception) {
            $this->assertNotInstanceOf(\dml_exception::class, $exception);
            $this->assertEquals('exception_tenantaccessdenied', $exception->errorcode);
        }

        $this->redirectHook(custom_tenant::class, function () {
            throw new \dml_read_exception('Simulated database error');
        });
        $this->expectException(\dml_read_exception::class);
        $accessmanager->require_tenant_access('schoolb');
    }

    /**
     * Tests that checking the access neither changes the current tenant nor the tenant of the access manager.
     */
    public function test_require_tenant_access_does_not_change_tenant(): void {
        $tenantfactory = \core\di::get(tenant_factory::class);
        $accessmanager = \core\di::get(access_manager::class);

        $this->setup_user('schoola', 'managetenants');
        $accessmanager->require_tenant_access('schoolb');
        $this->assertEquals('schoola', $tenantfactory->get()->get_identifier());
        $this->assertTrue($accessmanager->is_tenant_member());

        $this->setup_user('schoola');
        try {
            $accessmanager->require_tenant_access('schoolb');
            $this->fail('Accessing a foreign tenant must not be possible');
        } catch (\moodle_exception) {
            $this->assertEquals('schoola', $tenantfactory->get()->get_identifier());
            $this->assertTrue($accessmanager->is_tenant_member());
        }
    }
}

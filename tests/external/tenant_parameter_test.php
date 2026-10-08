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

use core_external\external_api;
use local_ai_manager\ai_manager_utils;
use local_ai_manager\local\config_manager;
use local_ai_manager\local\tenant_factory;
use local_ai_manager\local\tenant;
use stdClass;

/**
 * Tests for the tenant parameter of the web services get_ai_config and get_ai_info.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_manager\external\get_ai_config
 * @covers     \local_ai_manager\external\get_ai_info
 * @covers     ::local_ai_manager_override_webservice_execution
 */
final class tenant_parameter_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->redirectHook(\local_ai_manager\hook\userinfo_extend::class, fn() => null);
        $this->redirectHook(\local_ai_manager\hook\custom_tenant::class, fn() => null);
    }

    /**
     * Creates a user of the given tenant, logs the user in and starts with a fresh DI container.
     *
     * @param string $institution the institution field of the user, used as the tenant identifier
     * @param bool $managetenants whether the user gets the local/ai_manager:managetenants capability
     * @return stdClass the created user
     */
    private function setup_tenant_user(string $institution, bool $managetenants = false): stdClass {
        $user = $this->getDataGenerator()->create_user(['institution' => $institution]);
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/ai_manager:use', CAP_ALLOW, $roleid, SYSCONTEXTID);
        if ($managetenants) {
            assign_capability('local/ai_manager:managetenants', CAP_ALLOW, $roleid, SYSCONTEXTID);
        }
        role_assign($roleid, $user->id, SYSCONTEXTID);
        $this->setUser($user);
        \core\di::reset_container();
        return $user;
    }

    /**
     * Returns the identifier of the current tenant.
     *
     * @return string the identifier
     */
    private function get_current_tenant_identifier(): string {
        return \core\di::get(tenant_factory::class)->get()->get_identifier();
    }

    /**
     * Enables the given tenant without changing the current tenant.
     *
     * @param string $identifier the tenant identifier
     */
    private function enable_tenant(string $identifier): void {
        $tenantfactory = new tenant_factory();
        $tenantfactory->set(new tenant($identifier));
        (new config_manager($tenantfactory))->set_config('tenantenabled', 1);
    }

    /**
     * Tests that get_ai_config denies a foreign tenant to users who are neither member nor manager of it.
     */
    public function test_get_ai_config_denies_foreign_tenant(): void {
        $this->setup_tenant_user('schoola');

        $this->assertEquals(
            get_ai_config::execute(null, SYSCONTEXTID, ['chat']),
            get_ai_config::execute('schoola', SYSCONTEXTID, ['chat'])
        );
        try {
            get_ai_config::execute('schoolb', SYSCONTEXTID, ['chat']);
            $this->fail('Accessing a foreign tenant must not be possible');
        } catch (\moodle_exception $exception) {
            $this->assertEquals('exception_tenantaccessdenied', $exception->errorcode);
        }
        $this->assertEquals('schoola', $this->get_current_tenant_identifier());
    }

    /**
     * Tests that get_ai_info denies a foreign tenant to users who are neither member nor manager of it.
     */
    public function test_get_ai_info_denies_foreign_tenant(): void {
        $this->setup_tenant_user('schoola');

        $this->assertArrayHasKey('tools', get_ai_info::execute('schoola'));
        foreach (['schoolb', tenant::DEFAULT_IDENTIFIER] as $foreigntenant) {
            try {
                get_ai_info::execute($foreigntenant);
                $this->fail('Accessing a foreign tenant must not be possible');
            } catch (\moodle_exception $exception) {
                $this->assertEquals('exception_tenantaccessdenied', $exception->errorcode);
            }
        }
        $this->assertEquals('schoola', $this->get_current_tenant_identifier());
    }

    /**
     * Tests that tenant managers get the info and config of a foreign tenant.
     */
    public function test_manager_can_access_foreign_tenant(): void {
        $this->setup_tenant_user('schoola', true);
        // Only the foreign tenant is enabled, so the availability shows which tenant configuration has been used.
        $this->enable_tenant('schoolb');

        $availability = get_ai_config::execute('schoolb', SYSCONTEXTID, ['chat'])['availability'];
        $this->assertNotEquals(ai_manager_utils::AVAILABILITY_HIDDEN, $availability['available']);

        $info = get_ai_info::execute('schoolb');
        $this->assertNotEmpty($info['tools']);
        foreach ($info['tools'] as $tool) {
            $this->assertStringContainsString('tenant=schoolb', $tool['addurl']);
        }
    }

    /**
     * Tests that users who may manage a tenant because of the tenant context provided by a plugin get access to this tenant,
     * even if they do not belong to it.
     */
    public function test_tenant_manager_by_tenant_context_can_access_tenant(): void {
        $category = $this->getDataGenerator()->create_category();
        $categorycontext = \context_coursecat::instance($category->id);
        $this->redirectHook(
            \local_ai_manager\hook\custom_tenant::class,
            function (\local_ai_manager\hook\custom_tenant $customtenant) use ($categorycontext) {
                $customtenant->set_tenant_context($categorycontext);
            }
        );
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/ai_manager:manage', CAP_ALLOW, $roleid, SYSCONTEXTID);

        // A user without the manager role in the tenant context is denied.
        $this->setup_tenant_user('schoola');
        try {
            get_ai_info::execute('schoolb');
            $this->fail('Accessing a foreign tenant must not be possible');
        } catch (\moodle_exception $exception) {
            $this->assertEquals('exception_tenantaccessdenied', $exception->errorcode);
        }

        $manager = $this->setup_tenant_user('schoola');
        role_assign($roleid, $manager->id, $categorycontext->id);
        $this->assertArrayHasKey('tools', get_ai_info::execute('schoolb'));
        $this->assertArrayHasKey('availability', get_ai_config::execute('schoolb', SYSCONTEXTID, ['chat']));
    }

    /**
     * Tests that users managing all tenants are not locked out by a malformed tenant field value of their own profile.
     */
    public function test_managetenants_user_with_malformed_own_tenant(): void {
        $this->setup_tenant_user(' malformed tenant!', true);
        $this->enable_tenant('schoolb');

        $this->assertArrayHasKey('tools', get_ai_info::execute('schoolb'));
        $availability = get_ai_config::execute('schoolb', SYSCONTEXTID, ['chat'])['availability'];
        $this->assertNotEquals(ai_manager_utils::AVAILABILITY_HIDDEN, $availability['available']);
    }

    /**
     * Tests that the web services cannot be used as an oracle for enumerating existing tenants.
     *
     * Plugins providing the tenant context (like local_bycsauth) throw an exception for non-existing tenants. This must not
     * result in a different answer than for an existing foreign tenant.
     */
    public function test_no_oracle_for_existing_tenants(): void {
        $this->redirectHook(
            \local_ai_manager\hook\custom_tenant::class,
            function (\local_ai_manager\hook\custom_tenant $customtenant) {
                if (!in_array($customtenant->get_tenantidentifier(), ['schoola', 'schoolb'])) {
                    throw new \moodle_exception('Invalid school id');
                }
            }
        );
        $this->setup_tenant_user('schoola');

        foreach (['schoolb', 'notexisting', ' invalid identifier!'] as $foreigntenant) {
            $calls = [
                fn() => get_ai_info::execute($foreigntenant),
                fn() => get_ai_config::execute($foreigntenant, SYSCONTEXTID, ['chat']),
            ];
            foreach ($calls as $call) {
                try {
                    $call();
                    $this->fail('Accessing a foreign tenant must not be possible');
                } catch (\moodle_exception $exception) {
                    $this->assertEquals('exception_tenantaccessdenied', $exception->errorcode);
                }
                $this->assertEquals('schoola', $this->get_current_tenant_identifier());
            }
        }
    }

    /**
     * Tests that a tenant set by an external function does not leak into the next external function of the same request.
     *
     * This simulates a batch request via lib/ajax/service.php which executes several external functions in the same process.
     */
    public function test_tenant_does_not_leak_into_next_external_function(): void {
        $this->setup_tenant_user('schoola', true);
        // Only the foreign tenant is enabled, so the availability shows which tenant configuration has been used.
        $this->enable_tenant('schoolb');
        $_POST['sesskey'] = sesskey();

        $response = external_api::call_external_function(
            'local_ai_manager_get_ai_config',
            ['tenant' => 'schoolb', 'contextid' => SYSCONTEXTID, 'purposes' => ['chat']]
        );
        $this->assertFalse($response['error']);
        $this->assertNotEquals(ai_manager_utils::AVAILABILITY_HIDDEN, $response['data']['availability']['available']);

        $response = external_api::call_external_function(
            'local_ai_manager_get_ai_config',
            ['contextid' => SYSCONTEXTID, 'purposes' => ['chat']]
        );
        $this->assertFalse($response['error']);
        // The own tenant is not enabled, so the second call must not have used the tenant of the first call.
        $this->assertEquals(ai_manager_utils::AVAILABILITY_HIDDEN, $response['data']['availability']['available']);
        $this->assertEquals('schoola', $this->get_current_tenant_identifier());
    }
}

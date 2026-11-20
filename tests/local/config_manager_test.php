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
 * Tests for the config manager.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(config_manager::class)]
final class config_manager_test extends \advanced_testcase {
    /**
     * Data provider for test_get_max_requests_default.
     *
     * @return array Datasets with purpose, role and expected default.
     */
    public static function get_max_requests_default_provider(): array {
        return [
            'chat_basic' => [
                'purpose' => 'chat',
                'role' => userinfo::ROLE_BASIC,
                'expected' => userusage::MAX_REQUESTS_DEFAULT_ROLE_BASE,
            ],
            'chat_extended' => [
                'purpose' => 'chat',
                'role' => userinfo::ROLE_EXTENDED,
                'expected' => userusage::MAX_REQUESTS_DEFAULT_ROLE_EXTENDED,
            ],
            'searchquery_basic' => [
                'purpose' => 'searchquery',
                'role' => userinfo::ROLE_BASIC,
                'expected' => userusage::UNLIMITED_REQUESTS_PER_USER,
            ],
            'embedding_extended' => [
                'purpose' => 'embedding',
                'role' => userinfo::ROLE_EXTENDED,
                'expected' => userusage::UNLIMITED_REQUESTS_PER_USER,
            ],
        ];
    }

    /**
     * Tests the default request limits if no limit has been configured: internal purposes are unlimited.
     *
     * @param string $purpose the purpose name
     * @param int $role the role
     * @param int $expected the expected maximum amount of requests
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('get_max_requests_default_provider')]
    public function test_get_max_requests_default(string $purpose, int $role, int $expected): void {
        $this->resetAfterTest();
        $tenantfactory = new tenant_factory();
        $tenantfactory->set(new tenant('1234'));
        $configmanager = new config_manager($tenantfactory);
        $purposeobject = \core\di::get(connector_factory::class)->get_purpose_by_purpose_string($purpose);

        $this->assertSame($expected, $configmanager->get_max_requests($purposeobject, $role));

        // A configured limit also applies to internal purposes.
        $configmanager->set_config($purpose . '_max_requests_' . ($role === userinfo::ROLE_BASIC ? 'basic' : 'extended'), 5);
        $this->assertSame(5, $configmanager->get_max_requests($purposeobject, $role));
    }
}


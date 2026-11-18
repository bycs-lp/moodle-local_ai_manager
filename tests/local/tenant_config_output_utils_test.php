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
 * Tests for the tenant_config_output_utils class.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(tenant_config_output_utils::class)]
final class tenant_config_output_utils_test extends \advanced_testcase {
    /**
     * Tests which tenant config pages are available for which tenant.
     *
     * @param string $identifier the identifier of the tenant
     * @param string $page the page to check
     * @param bool $expected whether the page should be available
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('is_page_available_provider')]
    public function test_is_page_available(string $identifier, string $page, bool $expected): void {
        $this->assertSame($expected, tenant_config_output_utils::is_page_available(new tenant($identifier), $page));
    }

    /**
     * Data provider for {@see self::test_is_page_available()}.
     *
     * @return array the test cases
     */
    public static function is_page_available_provider(): array {
        return [
            'tenant_quota_config' => ['identifier' => 'tenanta', 'page' => 'quota_config.php', 'expected' => true],
            'template_tenant_config' => [
                'identifier' => tenant::TEMPLATE_IDENTIFIER,
                'page' => 'tenant_config.php',
                'expected' => true,
            ],
            'template_edit_instance' => [
                'identifier' => tenant::TEMPLATE_IDENTIFIER,
                'page' => 'edit_instance.php',
                'expected' => true,
            ],
            'template_purpose_config' => [
                'identifier' => tenant::TEMPLATE_IDENTIFIER,
                'page' => 'purpose_config.php',
                'expected' => true,
            ],
            'template_quota_config' => [
                'identifier' => tenant::TEMPLATE_IDENTIFIER,
                'page' => 'quota_config.php',
                'expected' => false,
            ],
            'template_statistics' => [
                'identifier' => tenant::TEMPLATE_IDENTIFIER,
                'page' => 'statistics.php',
                'expected' => false,
            ],
        ];
    }

    /**
     * Tests that pages not available for the template tenant cannot be opened for it.
     */
    public function test_setup_tenant_config_page_template_tenant(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        \core\di::get(tenant_factory::class)->set(new tenant(tenant::TEMPLATE_IDENTIFIER));
        tenant_config_output_utils::setup_tenant_config_page(new \moodle_url('/local/ai_manager/purpose_config.php'));

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('exception_templatetenantpage', 'local_ai_manager'));
        tenant_config_output_utils::setup_tenant_config_page(new \moodle_url('/local/ai_manager/quota_config.php'));
    }
}

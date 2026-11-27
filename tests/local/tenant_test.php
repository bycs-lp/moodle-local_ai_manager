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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Unit tests for the tenant data object.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Johannes Funk
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(tenant::class)]
final class tenant_test extends \advanced_testcase {
    /**
     * Tests the validation of identifier string in the tenant constructor.
     */
    #[DataProvider('tenant_name_validation_provider')]
    public function test_tenant_name_validation($name, $valid): void {
        $this->resetAfterTest();
        global $DB;

        if (!$valid) {
            $this->expectException(\invalid_parameter_exception::class);
            new \local_ai_manager\local\tenant($name);
        } else {
            $tenant = new \local_ai_manager\local\tenant($name);
            $this->assertSame($name, $tenant->get_identifier());

            // Make sure the allowed Unicode characters in tenants work with Moodles DB API by running a test query.
            $DB->insert_record('local_ai_manager_config', (object) [
                'tenant' => $tenant->get_identifier(),
                'configkey' => 'tenantenabled',
                'configvalue' => '1',
            ]);
            $record = $DB->get_record('local_ai_manager_config', ['tenant' => $tenant->get_identifier()]);
            $this->assertSame($name, $record->tenant);
        }
    }

    /**
     * Data provider.
     *
     * @return array identifiers with name and validity
     */
    public static function tenant_name_validation_provider(): array {
        return [
            'default_identifier' => [
                'name' => 'default',
                'valid' => true,
            ],
            'identifier_with_hyphen' => [
                'name' => 'tenant-1',
                'valid' => true,
            ],
            'identifier_with_space' => [
                'name' => 'Maths Department',
                'valid' => true,
            ],
            'identifier_with_hyphen_and_numbers' => [
                'name' => 'School-123 Munich',
                'valid' => true,
            ],
            'identifier_only_numeric' => [
                'name' => '123',
                'valid' => true,
            ],
            'identifier_with_multiple_spaces' => [
                'name' => 'a b c',
                'valid' => true,
            ],
            'identifier_with_underscore' => [
                'name' => 'underscore_university',
                'valid' => true,
            ],
            'identifier_with_exclamation_mark' => [
                'name' => 'name!',
                'valid' => false,
            ],
            'identifier_with_at_symbol' => [
                'name' => 'name@domain',
                'valid' => false,
            ],
            'identifier_with_hashtag' => [
                'name' => 'name#hash',
                'valid' => false,
            ],
            'identifier_with_percent' => [
                'name' => 'percent%',
                'valid' => false,
            ],
            'identifier_with_backslash' => [
                'name' => 'newline\n',
                'valid' => false,
            ],
            'identifier_with_umlaut' => [
                'name' => 'München',
                'valid' => true,
            ],
            'identifier_with_slashes' => [
                'name' => '/slashes/',
                'valid' => false,
            ],
            'identifier_with_comma' => [
                'name' => 'comma,comma',
                'valid' => false,
            ],
            'identifier_with_HTML' => [
                'name' => 'HTML-Tag<br />',
                'valid' => false,
            ],
            'identifier_with_leading_whitespace' => [
                'name' => ' Whitespace University',
                'valid' => false,
            ],
            'identifier_with_trailing_whitespace' => [
                'name' => 'Whitespace School ',
                'valid' => false,
            ],
            'identifier_french_compound' => [
                'name' => 'Île-de-France',
                'valid' => true,
            ],
            'identifier_spanish_tilde' => [
                'name' => 'España',
                'valid' => true,
            ],
            'identifier_german_eszett' => [
                'name' => 'Straße 42',
                'valid' => true,
            ],
            'identifier_cjk' => [
                'name' => '北京大学',
                'valid' => false,
            ],
            'identifier_arabic' => [
                'name' => 'مرحبا',
                'valid' => false,
            ],
            'identifier_cyrillic' => [
                'name' => 'Москва',
                'valid' => false,
            ],
            'identifier_greek' => [
                'name' => 'Αθήνα',
                'valid' => false,
            ],
            'identifier_emoji_mixed' => [
                'name' => 'Schule 🏫',
                'valid' => false,
            ],
            'identifier_vietnamese' => [
                'name' => 'Hà Nội',
                'valid' => true,
            ],
            'identifier_ipa' => [
                'name' => 'ɐɹʇ',
                'valid' => true,
            ],
        ];
    }

    /**
     * Tests that the template tenant is not being passed to plugins implementing the custom_tenant hook.
     *
     * All other tenants still have to be passed to the hook, so plugins are able to validate them.
     */
    public function test_template_tenant_not_passed_to_custom_tenant_hook(): void {
        $this->resetAfterTest();
        // Simulates a plugin implementing the custom_tenant hook which validates the tenant by throwing an exception for
        // tenants it does not know.
        $this->redirectHook(custom_tenant::class, function (custom_tenant $customtenant) {
            if ($customtenant->get_tenantidentifier() !== 'tenanta') {
                throw new \coding_exception('Unknown tenant');
            }
        });

        $templatetenant = new tenant(tenant::TEMPLATE_IDENTIFIER);
        $this->assertTrue($templatetenant->is_template_tenant());
        $this->assertFalse($templatetenant->is_default_tenant());
        $this->assertSame(\context_system::instance(), $templatetenant->get_context());
        $this->assertSame(get_string('templatetenantname', 'local_ai_manager'), $templatetenant->get_fullname());

        $knowntenant = new tenant('tenanta');
        $this->assertFalse($knowntenant->is_template_tenant());
        $this->assertSame(\context_system::instance(), $knowntenant->get_context());

        $this->expectException(\coding_exception::class);
        (new tenant('unknowntenant'))->get_context();
    }

    /**
     * Tests that the template tenant cannot be restricted.
     */
    public function test_template_tenant_always_allowed(): void {
        $this->resetAfterTest();
        set_config('restricttenants', 1, 'local_ai_manager');
        set_config('allowedtenants', 'tenanta', 'local_ai_manager');
        $this->assertTrue((new tenant('tenanta'))->is_tenant_allowed());
        $this->assertFalse((new tenant('tenantb'))->is_tenant_allowed());
        $this->assertTrue((new tenant(tenant::TEMPLATE_IDENTIFIER))->is_tenant_allowed());
    }

    /**
     * Tests that a user cannot belong to the template tenant.
     */
    public function test_user_cannot_belong_to_template_tenant(): void {
        $this->resetAfterTest();
        $tenantfield = get_config('local_ai_manager', 'tenantcolumn');
        $this->setUser($this->getDataGenerator()->create_user([$tenantfield => 'tenanta']));
        $this->assertSame('tenanta', (new tenant())->get_identifier());

        $this->setUser($this->getDataGenerator()->create_user([$tenantfield => tenant::TEMPLATE_IDENTIFIER]));
        $this->expectException(\invalid_parameter_exception::class);
        new tenant();
    }
}

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

namespace local_ai_manager\output;

use local_ai_manager\local\tenant;

/**
 * Tests for instancetable.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(instancetable::class)]
final class instancetable_test extends \advanced_testcase {
    /**
     * Tests that the preview of a table contains no links and no template tenant modal.
     */
    public function test_export_for_template_preview(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        /** @var \local_ai_manager_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_ai_manager');
        $generator->create_instance(['name' => 'Template A', 'tenant' => tenant::TEMPLATE_IDENTIFIER]);
        $generator->create_tenant_config(['tenant' => tenant::TEMPLATE_IDENTIFIER, 'configkey' => 'tenantenabled', 'value' => 1]);
        $output = $PAGE->get_renderer('core');

        $data = (new instancetable(new tenant(tenant::TEMPLATE_IDENTIFIER), true))->export_for_template($output);
        $this->assert_no_links($data);
        $this->assertFalse($data->templatetenantmodal);

        // Without preview the table of the template tenant contains links, but also no modal for applying itself.
        $data = (new instancetable(new tenant(tenant::TEMPLATE_IDENTIFIER)))->export_for_template($output);
        $this->assertCount(1, $data->instances);
        $this->assertStringContainsString('<a ', $data->instances[0]['name']);
        $this->assertStringContainsString('<a ', $data->instances[0]['nopurposeslink']);
        $this->assertFalse($data->templatetenantmodal);
    }

    /**
     * Tests that the template tenant modal is only offered to other tenants if the template tenant is enabled.
     */
    public function test_export_for_template_templatetenantmodal(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        /** @var \local_ai_manager_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_ai_manager');
        $generator->create_instance(['name' => 'Template A', 'tenant' => tenant::TEMPLATE_IDENTIFIER]);
        $generator->create_instance(['name' => 'Own instance', 'tenant' => 'tenanta']);
        $tenant = new tenant('tenanta');
        $output = $PAGE->get_renderer('core');

        // Template tenant disabled.
        $data = (new instancetable($tenant))->export_for_template($output);
        $this->assertFalse($data->templatetenantmodal);

        $generator->create_tenant_config(['tenant' => tenant::TEMPLATE_IDENTIFIER, 'configkey' => 'tenantenabled', 'value' => 1]);
        $data = (new instancetable($tenant))->export_for_template($output);
        $this->assertCount(1, $data->instances);
        $this->assertStringContainsString('Own instance', $data->instances[0]['name']);
        $this->assertIsArray($data->templatetenantmodal);
        $this->assertStringContainsString('applytemplatetenant=1', $data->templatetenantmodal['applyurl']);
        $this->assertStringContainsString('tenant=tenanta', $data->templatetenantmodal['applyurl']);
        // The modal contains the read-only preview of the template tenant.
        $previewdata = $data->templatetenantmodal['instancetable'];
        $this->assertSame(tenant::TEMPLATE_IDENTIFIER, $previewdata->tenant);
        $this->assertCount(1, $previewdata->instances);
        $this->assertSame('Template A', $previewdata->instances[0]['name']);
        $this->assert_no_links($previewdata);
        $this->assertFalse($previewdata->templatetenantmodal);
    }

    /**
     * Asserts that the exported instance table data does not contain any links.
     *
     * @param \stdClass $data the exported data of the instance table
     */
    private function assert_no_links(\stdClass $data): void {
        $this->assertNotEmpty($data->instances);
        foreach ($data->instances as $instance) {
            $this->assertStringNotContainsString('<a ', $instance['name']);
            $this->assertSame('', $instance['nopurposeslink']);
        }
    }
}

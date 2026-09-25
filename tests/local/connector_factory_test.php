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

use local_ai_manager\base_purpose;
use local_ai_manager\plugininfo\aipurpose;
use local_ai_manager\plugininfo\aitool;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unit tests for the connector_factory class of local_ai_manager.
 *
 * @package   local_ai_manager
 * @copyright 2026 ISB Bayern
 * @author    Philipp Memmel
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(connector_factory::class)]
final class connector_factory_test extends \advanced_testcase {
    /**
     * Tests the method get_new_instance.
     */
    public function test_get_new_instance(): void {
        $this->resetAfterTest();
        $instance = \core\di::get(connector_factory::class)->get_new_instance('chatgpt');
        $this->assertInstanceOf(\aitool_chatgpt\instance::class, $instance);
        $this->assertEquals('chatgpt', $instance->get_connector());
        $this->assertEquals(0, $instance->get_id());
    }

    /**
     * Tests the methods get_connector_instance_by_id and instance_exists.
     */
    public function test_get_connector_instance_by_id_and_instance_exists(): void {
        $this->resetAfterTest();
        /** @var \local_ai_manager_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_ai_manager');
        $model = $generator->create_model(['connectors' => ['chatgpt']]);
        $instance = $generator->create_instance(['modelid' => $model->id]);

        $connectorfactory = \core\di::get(connector_factory::class);
        $this->assertTrue($connectorfactory->instance_exists($instance->get_id()));
        $this->assertFalse($connectorfactory->instance_exists($instance->get_id() + 1));

        // Use a fresh factory to make sure the instance is being loaded from the database.
        $connectorfactory = new connector_factory(\core\di::get(config_manager::class));
        $loadedinstance = $connectorfactory->get_connector_instance_by_id($instance->get_id());
        $this->assertInstanceOf(\aitool_chatgpt\instance::class, $loadedinstance);
        $this->assertEquals($instance->get_id(), $loadedinstance->get_id());
        $this->assertEquals((int) $model->id, $loadedinstance->get_model_id());

        $this->expectException(\dml_missing_record_exception::class);
        $connectorfactory->get_connector_instance_by_id($instance->get_id() + 1);
    }

    /**
     * Tests the methods get_connector_instance_by_purpose and get_connector_by_purpose.
     */
    public function test_get_connector_instance_and_connector_by_purpose(): void {
        $this->resetAfterTest();
        /** @var \local_ai_manager_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_ai_manager');
        $model = $generator->create_model(['connectors' => ['chatgpt']]);
        $instance = $generator->create_instance(['modelid' => $model->id]);
        $configmanager = \core\di::get(config_manager::class);
        $connectorfactory = new connector_factory($configmanager);

        // No instance configured for the purpose yet.
        $this->assertNull($connectorfactory->get_connector_instance_by_purpose('chat', userinfo::ROLE_BASIC));
        $this->assertNull($connectorfactory->get_connector_by_purpose('chat', userinfo::ROLE_BASIC));

        $configmanager->set_config(
            base_purpose::get_purpose_tool_config_key('chat', userinfo::ROLE_BASIC),
            (string) $instance->get_id()
        );
        $purposeinstance = $connectorfactory->get_connector_instance_by_purpose('chat', userinfo::ROLE_BASIC);
        $this->assertEquals($instance->get_id(), $purposeinstance->get_id());

        $connector = $connectorfactory->get_connector_by_purpose('chat', userinfo::ROLE_BASIC);
        $this->assertInstanceOf(\aitool_chatgpt\connector::class, $connector);
        $this->assertEquals($instance->get_id(), $connector->get_instance()->get_id());

        // Other roles are not affected.
        $this->assertNull($connectorfactory->get_connector_by_purpose('chat', userinfo::ROLE_EXTENDED));
    }

    /**
     * Tests the method get_connector_by_connectorname.
     */
    public function test_get_connector_by_connectorname(): void {
        $this->resetAfterTest();
        $connector = \core\di::get(connector_factory::class)->get_connector_by_connectorname('chatgpt');
        $this->assertInstanceOf(\aitool_chatgpt\connector::class, $connector);
        $this->assertEquals('chatgpt', $connector->get_instance()->get_connector());
    }

    /**
     * Tests the method get_connector_by_connectorname_and_model.
     */
    public function test_get_connector_by_connectorname_and_model(): void {
        $this->resetAfterTest();
        /** @var \local_ai_manager_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_ai_manager');
        $model = $generator->create_model(['connectors' => ['chatgpt']]);
        $connectorfactory = \core\di::get(connector_factory::class);

        $connector = $connectorfactory->get_connector_by_connectorname_and_model('chatgpt', 'test-model');
        $this->assertInstanceOf(\aitool_chatgpt\connector::class, $connector);
        $this->assertEquals((int) $model->id, $connector->get_instance()->get_model_id());

        // A model which exists, but is not assigned to the connector, must be rejected.
        $generator->create_model(['name' => 'unassigned-model']);
        $this->expectException(\coding_exception::class);
        $this->expectExceptionMessage('Model unassigned-model is not supported by connector chatgpt');
        $connectorfactory->get_connector_by_connectorname_and_model('chatgpt', 'unassigned-model');
    }

    /**
     * Tests that get_connector_by_connectorname_and_model throws an exception for a not existing model.
     */
    public function test_get_connector_by_connectorname_and_model_not_existing(): void {
        $this->resetAfterTest();
        $this->expectException(\moodle_exception::class);
        \core\di::get(connector_factory::class)->get_connector_by_connectorname_and_model('chatgpt', 'notexistingmodel');
    }

    /**
     * Tests the method get_connector_by_connectorname_and_model_id.
     */
    public function test_get_connector_by_connectorname_and_model_id(): void {
        $this->resetAfterTest();
        /** @var \local_ai_manager_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_ai_manager');
        $model = $generator->create_model(['connectors' => ['chatgpt']]);
        $connectorfactory = \core\di::get(connector_factory::class);

        $connector = $connectorfactory->get_connector_by_connectorname_and_model_id('chatgpt', (int) $model->id);
        $this->assertInstanceOf(\aitool_chatgpt\connector::class, $connector);
        $this->assertEquals((int) $model->id, $connector->get_instance()->get_model_id());

        $unassignedmodel = $generator->create_model(['name' => 'unassigned-model']);
        $this->expectException(\coding_exception::class);
        $this->expectExceptionMessage('Model ID ' . $unassignedmodel->id . ' is not supported by connector chatgpt');
        $connectorfactory->get_connector_by_connectorname_and_model_id('chatgpt', (int) $unassignedmodel->id);
    }

    /**
     * Tests the method get_connector_instances_for_purpose.
     */
    public function test_get_connector_instances_for_purpose(): void {
        $this->resetAfterTest();
        /** @var \local_ai_manager_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_ai_manager');
        $model = $generator->create_model(['connectors' => ['chatgpt']]);
        $instance = $generator->create_instance(['modelid' => $model->id]);

        $instances = connector_factory::get_connector_instances_for_purpose('chat');
        $this->assertArrayHasKey($instance->get_id(), $instances);

        // The text generation model does not support image generation.
        $instances = connector_factory::get_connector_instances_for_purpose('imggen');
        $this->assertArrayNotHasKey($instance->get_id(), $instances);

        // Instances of disabled connectors are not being returned.
        aitool::enable_plugin('chatgpt', 0);
        $instances = connector_factory::get_connector_instances_for_purpose('chat');
        $this->assertArrayNotHasKey($instance->get_id(), $instances);
    }

    /**
     * Tests the method get_purpose_by_purpose_string.
     */
    public function test_get_purpose_by_purpose_string(): void {
        $this->resetAfterTest();
        $connectorfactory = \core\di::get(connector_factory::class);

        // Enabled purpose is being returned.
        aipurpose::enable_plugin('chat', 1);
        $purpose = $connectorfactory->get_purpose_by_purpose_string('chat');
        $this->assertInstanceOf(\aipurpose_chat\purpose::class, $purpose);

        // Enabled purpose is also being returned when ignoring disabled purposes.
        $purpose = $connectorfactory->get_purpose_by_purpose_string('chat', true);
        $this->assertInstanceOf(\aipurpose_chat\purpose::class, $purpose);

        // Disabled purpose is being returned if we explicitly ignore the disabled state.
        aipurpose::enable_plugin('chat', 0);
        $purpose = $connectorfactory->get_purpose_by_purpose_string('chat', true);
        $this->assertInstanceOf(\aipurpose_chat\purpose::class, $purpose);

        // Disabled purpose throws an exception by default.
        $this->expectException(\coding_exception::class);
        $this->expectExceptionMessage('Purpose chat is not enabled');
        $connectorfactory->get_purpose_by_purpose_string('chat');
    }

    /**
     * Tests that get_purpose_by_purpose_string throws an exception if an empty purpose string is passed.
     */
    public function test_get_purpose_by_purpose_string_empty(): void {
        $connectorfactory = \core\di::get(connector_factory::class);
        $this->expectException(\coding_exception::class);
        $this->expectExceptionMessage('No purpose string passed');
        $connectorfactory->get_purpose_by_purpose_string('', true);
    }
}

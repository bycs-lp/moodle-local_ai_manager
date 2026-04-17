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

namespace local_ai_manager;

use advanced_testcase;
use ReflectionMethod;

/**
 * Unit tests for the help button fallback logic in base_instance.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Fabian Barbuia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_ai_manager\base_instance::add_help_button_with_fallback
 */
final class base_instance_help_button_test extends advanced_testcase {
    /**
     * Data provider for default help strings that must exist in local_ai_manager.
     *
     * @return array
     */
    public static function default_help_strings_provider(): array {
        return [
            'endpoint_help' => ['endpoint_help'],
            'apikey_help' => ['apikey_help'],
            'model_help' => ['model_help'],
            'infolink_help' => ['infolink_help'],
            'instancename_help' => ['instancename_help'],
            'temperature_defaultsetting_help' => ['temperature_defaultsetting_help'],
            'use_openai_by_azure_heading_help' => ['use_openai_by_azure_heading_help'],
            'use_openai_by_azure_name_help' => ['use_openai_by_azure_name_help'],
            'use_openai_by_azure_deploymentid_help' => ['use_openai_by_azure_deploymentid_help'],
            'use_openai_by_azure_apiversion_help' => ['use_openai_by_azure_apiversion_help'],
            'serviceaccountjson_help' => ['serviceaccountjson_help'],
        ];
    }

    /**
     * Ensure each required default help string exists in local_ai_manager.
     *
     * @dataProvider default_help_strings_provider
     * @param string $stringkey The lang string key to check.
     */
    public function test_default_help_string_exists(string $stringkey): void {
        $this->assertTrue(
            get_string_manager()->string_exists($stringkey, 'local_ai_manager'),
            "Default help string '{$stringkey}' must exist in local_ai_manager lang file."
        );
    }

    /**
     * Data provider for subplugin endpoint_help overrides.
     *
     * @return array
     */
    public static function subplugin_endpoint_help_provider(): array {
        return [
            'chatgpt' => ['chatgpt'],
            'dalle' => ['dalle'],
            'gemini' => ['gemini'],
            'ollama' => ['ollama'],
            'telli' => ['telli'],
            'openaitts' => ['openaitts'],
            'googlesynthesize' => ['googlesynthesize'],
            'imagen' => ['imagen'],
        ];
    }

    /**
     * Ensure each subplugin defines its own endpoint_help override.
     *
     * @dataProvider subplugin_endpoint_help_provider
     * @param string $connector The connector name.
     */
    public function test_subplugin_endpoint_help_override_exists(string $connector): void {
        $component = 'aitool_' . $connector;
        $this->assertTrue(
            get_string_manager()->string_exists('endpoint_help', $component),
            "Subplugin '{$component}' should define its own 'endpoint_help' string."
        );
    }

    /**
     * Data provider for subplugin model_help overrides.
     *
     * @return array
     */
    public static function subplugin_model_help_provider(): array {
        return [
            'chatgpt' => ['chatgpt'],
            'dalle' => ['dalle'],
            'gemini' => ['gemini'],
            'ollama' => ['ollama'],
            'telli' => ['telli'],
            'openaitts' => ['openaitts'],
            'imagen' => ['imagen'],
        ];
    }

    /**
     * Ensure each subplugin defines its own model_help override.
     *
     * @dataProvider subplugin_model_help_provider
     * @param string $connector The connector name.
     */
    public function test_subplugin_model_help_override_exists(string $connector): void {
        $component = 'aitool_' . $connector;
        $this->assertTrue(
            get_string_manager()->string_exists('model_help', $component),
            "Subplugin '{$component}' should define its own 'model_help' string."
        );
    }

    /**
     * Ensure the fallback resolves to local_ai_manager when subplugin string does not exist.
     */
    public function test_fallback_to_default_when_subplugin_string_missing(): void {
        $stringmanager = get_string_manager();
        $fakeconnector = 'nonexistent_connector_xyz_42';

        $this->assertFalse(
            $stringmanager->string_exists('endpoint_help', 'aitool_' . $fakeconnector),
            'Subplugin string should not exist for a fake connector.'
        );

        $this->assertTrue(
            $stringmanager->string_exists('endpoint_help', 'local_ai_manager'),
            "Default 'endpoint_help' must exist in local_ai_manager as fallback."
        );
    }

    /**
     * Verify the method signature of add_help_button_with_fallback.
     */
    public function test_add_help_button_with_fallback_method_signature(): void {
        $method = new ReflectionMethod(base_instance::class, 'add_help_button_with_fallback');
        $this->assertTrue($method->isPublic(), 'Method should be public.');
        $this->assertTrue($method->isStatic(), 'Method should be static.');
        $this->assertCount(4, $method->getParameters(), 'Method should accept exactly 4 parameters.');
    }

    /**
     * Data provider for connector-specific help string overrides.
     *
     * @return array
     */
    public static function connector_specific_help_strings_provider(): array {
        return [
            'gemini googlebackend_help' => ['gemini', 'googlebackend_help'],
            'telli apikey_help' => ['telli', 'apikey_help'],
        ];
    }

    /**
     * Ensure connector-specific help string overrides exist.
     *
     * @dataProvider connector_specific_help_strings_provider
     * @param string $connector The connector name.
     * @param string $stringkey The lang string key to check.
     */
    public function test_connector_specific_help_string_exists(string $connector, string $stringkey): void {
        $component = 'aitool_' . $connector;
        $this->assertTrue(
            get_string_manager()->string_exists($stringkey, $component),
            "Subplugin '{$component}' should define '{$stringkey}'."
        );
    }
}

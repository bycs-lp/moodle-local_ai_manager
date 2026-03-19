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

namespace aitool_chatgpt;

use local_ai_manager\local\aitool_option_azure;
use local_ai_manager\local\connector_factory;

/**
 * Tests for ChatGPT connector.
 *
 * @package    aitool_chatgpt
 * @copyright  2026 ISB Bayern
 * @author     Thomas Schönlein
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \aitool_chatgpt\connector
 */
final class connector_test extends \advanced_testcase {
    /**
     * Helper to invoke the protected get_endpoint_url() method.
     */
    private function call_get_endpoint_url(connector $connector): string {
        return (new \ReflectionMethod($connector, 'get_endpoint_url'))->invoke($connector);
    }

    /**
     * Creates a connector with a mocked instance returning the given endpoint value.
     */
    private function make_connector(string $endpoint): connector {
        $instance = $this->getMockBuilder(\local_ai_manager\base_instance::class)
            ->disableOriginalConstructor()
            ->getMock();
        $instance->method('get_endpoint')->willReturn($endpoint);
        return new connector($instance);
    }

    /**
     * Tests that get_endpoint_url() returns the hardcoded default when no endpoint is configured.
     *
     * @covers ::get_endpoint_url
     */
    public function test_get_endpoint_url_returns_default_when_empty(): void {
        $this->assertEquals(
            'https://api.openai.com/v1/chat/completions',
            $this->call_get_endpoint_url($this->make_connector(''))
        );
    }

    /**
     * Tests that get_endpoint_url() returns the configured custom endpoint when one is set.
     *
     * @covers ::get_endpoint_url
     */
    public function test_get_endpoint_url_returns_custom_when_set(): void {
        $customurl = 'https://my-proxy.example.com/v1/chat/completions';
        $this->assertEquals(
            $customurl,
            $this->call_get_endpoint_url($this->make_connector($customurl))
        );
    }

    /**
     * Test that the Azure model is only available for supported purposes.
     *
     * @throws \coding_exception
     * @covers \aitool_chatgpt\connector::get_models_by_purpose
     */
    public function test_get_models_by_purpose_contains_azure_model_only_for_supported_purposes(): void {
        $connectorfactory = \core\di::get(connector_factory::class);
        $connector = $connectorfactory->get_connector_by_connectorname('chatgpt');
        $modelname = aitool_option_azure::get_azure_model_name('chatgpt');
        $modelsbypurpose = $connector->get_models_by_purpose();

        foreach (['chat', 'feedback', 'singleprompt', 'translate', 'itt', 'questiongeneration', 'agent'] as $purpose) {
            $this->assertContains($modelname, $modelsbypurpose[$purpose]);
        }

        $this->assertNotContains($modelname, $modelsbypurpose['tts']);
        $this->assertNotContains($modelname, $modelsbypurpose['imggen']);
    }
}

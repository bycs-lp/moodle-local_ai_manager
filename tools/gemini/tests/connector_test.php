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

namespace aitool_gemini;

/**
 * Tests for the aitool_gemini connector.
 *
 * @package    aitool_gemini
 * @copyright  2026 ISB Bayern
 * @author     Thomas Schönlein
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \aitool_gemini\connector
 */
final class connector_test extends \advanced_testcase {
    /** @var string Service account JSON used in tests. */
    private const SERVICE_ACCOUNT_JSON = '{"project_id":"test-project","private_key_id":"key1",'
        . '"private_key":"key","client_email":"test@test.iam.gserviceaccount.com"}';

    /**
     * Helper to invoke the protected get_endpoint_url() method.
     */
    private function call_get_endpoint_url(connector $connector): string {
        return (new \ReflectionMethod($connector, 'get_endpoint_url'))->invoke($connector);
    }

    /**
     * Creates a connector with a mocked instance for the given backend configuration.
     */
    private function make_connector(string $endpoint, string $backend, string $serviceaccountjson = ''): connector {
        $instance = $this->getMockBuilder(\local_ai_manager\base_instance::class)
            ->disableOriginalConstructor()
            ->getMock();
        $instance->method('get_endpoint')->willReturn($endpoint);
        $instance->method('get_customfield2')->willReturn($backend);
        $instance->method('get_customfield3')->willReturn($serviceaccountjson);
        $instance->method('get_model')->willReturn('gemini-2.0-flash');
        return new connector($instance);
    }

    public function test_get_endpoint_url_googleai_returns_generated_url(): void {
        $this->assertEquals(
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent',
            $this->call_get_endpoint_url($this->make_connector('', instance::GOOGLE_BACKEND_GOOGLEAI))
        );
    }

    public function test_get_endpoint_url_vertexai_returns_generated_url(): void {
        $this->assertEquals(
            'https://europe-north1-aiplatform.googleapis.com/v1/projects/test-project'
                . '/locations/europe-north1/publishers/google/models/gemini-2.0-flash:generateContent',
            $this->call_get_endpoint_url(
                $this->make_connector('', instance::GOOGLE_BACKEND_VERTEXAI, self::SERVICE_ACCOUNT_JSON)
            )
        );
    }

    public function test_get_endpoint_url_returns_custom_when_set(): void {
        $customurl = 'https://my-proxy.example.com/gemini';
        $this->assertEquals(
            $customurl,
            $this->call_get_endpoint_url(
                $this->make_connector($customurl, instance::GOOGLE_BACKEND_GOOGLEAI)
            )
        );
    }

    public function test_get_endpoint_url_vertexai_empty_serviceaccount_returns_empty(): void {
        $this->assertEquals(
            '',
            $this->call_get_endpoint_url($this->make_connector('', instance::GOOGLE_BACKEND_VERTEXAI, ''))
        );
    }
}

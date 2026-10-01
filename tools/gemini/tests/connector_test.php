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
 * @covers     \aitool_gemini\connector
 */
final class connector_test extends \advanced_testcase {
    /** @var string Service account JSON used in tests. */
    private const SERVICE_ACCOUNT_JSON = '{"project_id":"test-project","private_key_id":"key1",'
        . '"private_key":"key","client_email":"test@test.iam.gserviceaccount.com"}';

    /**
     * Helper to invoke the protected get_endpoint_url() method.
     *
     * @param connector $connector The connector object to return the endpoint URL for
     * @return string The endpoint URL
     */
    private function call_get_endpoint_url(connector $connector): string {
        return (new \ReflectionMethod($connector, 'get_endpoint_url'))->invoke($connector);
    }

    /**
     * Creates a connector with a mocked instance for the given backend configuration.
     *
     * @param string $endpoint the endpoint of this instance
     * @param string $backend the backend of this instance
     * @param string $serviceaccountjson the service account JSON string
     * @return connector
     */
    private function make_connector(string $endpoint, string $backend, string $serviceaccountjson = ''): connector {
        $instance = $this->getMockBuilder(\local_ai_manager\base_instance::class)
            ->disableOriginalConstructor()
            ->getMock();
        $instance->method('get_endpoint')->willReturn($endpoint);
        $instance->method('get_customfield2')->willReturn($backend);
        $instance->method('get_customfield3')->willReturn($serviceaccountjson);
        $instance->method('get_model_name')->willReturn('gemini-2.0-flash');
        $instance->method('get_model_id')->willReturn(1);
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

    public function test_get_endpoint_url_vertexai_invalid_serviceaccount_returns_empty(): void {
        $this->assertEquals(
            '',
            $this->call_get_endpoint_url($this->make_connector('', instance::GOOGLE_BACKEND_VERTEXAI, '{'))
        );
    }

    /**
     * Builds a connector whose allowed mimetypes are fixed, plus matching request options.
     *
     * @param string $image value of the image request option
     * @return array [connector, request_options]
     */
    private function make_image_request(string $image): array {
        $instance = $this->getMockBuilder(\local_ai_manager\base_instance::class)
            ->disableOriginalConstructor()
            ->getMock();
        $instance->method('get_endpoint')->willReturn('');
        $instance->method('get_customfield2')->willReturn(instance::GOOGLE_BACKEND_GOOGLEAI);
        $instance->method('get_model_name')->willReturn('gemini-2.0-flash');
        $instance->method('get_model_id')->willReturn(1);
        $instance->method('get_model_object')->willReturn(null);

        $connector = $this->getMockBuilder(connector::class)
            ->setConstructorArgs([$instance])
            ->onlyMethods(['allowed_mimetypes'])
            ->getMock();
        $connector->method('allowed_mimetypes')->willReturn(['image/png', 'image/jpeg']);

        $requestoptions = $this->getMockBuilder(\local_ai_manager\request_options::class)
            ->disableOriginalConstructor()
            ->getMock();
        $requestoptions->method('get_options')->willReturn(['image' => $image]);

        return [$connector, $requestoptions];
    }

    /**
     * The image option must never be handed to a stream-opening function, and must match the allowlist.
     *
     * @covers \aitool_gemini\connector::get_prompt_data
     */
    #[\PHPUnit\Framework\Attributes\Group('baseline')]
    public function test_get_prompt_data_rejects_non_data_url_image(): void {
        $this->resetAfterTest();

        $images = [
            'http://127.0.0.1:18099/latest/meta-data/',
            'file:///etc/passwd',
            'php://filter/convert.base64-encode/resource=/etc/passwd',
            'data:text/html;base64,PHNjcmlwdD4=',
        ];
        foreach ($images as $image) {
            [$connector, $requestoptions] = $this->make_image_request($image);
            try {
                $connector->get_prompt_data('describe', $requestoptions);
                $this->fail('Expected rejection of image value: ' . $image);
            } catch (\moodle_exception $e) {
                $this->assertEquals('exception_badmessageformat', $e->errorcode);
            }
        }
    }

    /**
     * A well formed data URL of an allowed mimetype must still be forwarded unchanged.
     *
     * @covers \aitool_gemini\connector::get_prompt_data
     */
    #[\PHPUnit\Framework\Attributes\Group('baseline')]
    public function test_get_prompt_data_accepts_allowed_data_url(): void {
        $this->resetAfterTest();

        $base64 = 'iVBORw0KGgoAAAANSUhEUg==';
        [$connector, $requestoptions] = $this->make_image_request('data:image/png;base64,' . $base64);
        $params = $connector->get_prompt_data('describe', $requestoptions);

        $inlinedata = $params['contents'][0]['parts'][1]['inline_data'];
        $this->assertEquals('image/png', $inlinedata['mime_type']);
        $this->assertEquals($base64, $inlinedata['data']);
    }
}

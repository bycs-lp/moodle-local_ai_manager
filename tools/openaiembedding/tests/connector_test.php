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

namespace aitool_openaiembedding;

use GuzzleHttp\Psr7\Utils;
use local_ai_manager\request_options;

/**
 * Tests for OpenAI embedding connector.
 *
 * @package    aitool_openaiembedding
 * @copyright  2026 ISB Bayern
 * @author     GitHub Copilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \aitool_openaiembedding\connector
 */
final class connector_test extends \advanced_testcase {
    /**
     * Helper to invoke the protected get_endpoint_url() method.
     *
     * @param connector $connector the connector to call the method on
     * @return string the resolved endpoint url
     */
    private function call_get_endpoint_url(connector $connector): string {
        return (new \ReflectionMethod($connector, 'get_endpoint_url'))->invoke($connector);
    }

    /**
     * Creates a connector with a mocked instance returning the given values.
     *
     * @param string $endpoint the endpoint value the mocked instance should return
     * @param bool $azureenabled whether the mocked instance reports azure as enabled
     * @param string $modelname the model name the mocked instance should return
     * @return connector the connector using the mocked instance
     */
    private function make_connector(
        string $endpoint,
        bool $azureenabled = false,
        string $modelname = 'text-embedding-3-small'
    ): connector {
        $instance = $this->getMockBuilder(instance::class)
            ->disableOriginalConstructor()
            ->getMock();
        $instance->method('get_endpoint')->willReturn($endpoint);
        $instance->method('azure_enabled')->willReturn($azureenabled);
        $instance->method('get_model_name')->willReturn($modelname);
        return new connector($instance);
    }

    /**
     * Tests that get_endpoint_url() returns the hardcoded default when no endpoint is configured.
     *
     * @covers ::get_endpoint_url
     */
    public function test_get_endpoint_url_returns_default_when_empty(): void {
        $this->assertEquals(
            connector::DEFAULT_OPENAI_EMBEDDINGS_ENDPOINT,
            $this->call_get_endpoint_url($this->make_connector(''))
        );
    }

    /**
     * Tests that get_endpoint_url() returns the configured custom endpoint when one is set.
     *
     * @covers ::get_endpoint_url
     */
    public function test_get_endpoint_url_returns_custom_when_set(): void {
        $customurl = 'https://my-proxy.example.com/v1/embeddings';
        $this->assertEquals(
            $customurl,
            $this->call_get_endpoint_url($this->make_connector($customurl))
        );
    }

    /**
     * Tests that get_prompt_data() sends the input and model when azure is disabled.
     *
     * @covers ::get_prompt_data
     */
    public function test_get_prompt_data_without_azure_contains_model(): void {
        $connector = $this->make_connector('', false, 'text-embedding-3-large');
        $data = $connector->get_prompt_data('hello world', $this->createMock(request_options::class));
        $this->assertEquals('hello world', $data['input']);
        $this->assertEquals('text-embedding-3-large', $data['model']);
    }

    /**
     * Tests that get_prompt_data() omits the model when azure is enabled.
     *
     * @covers ::get_prompt_data
     */
    public function test_get_prompt_data_with_azure_omits_model(): void {
        $connector = $this->make_connector('', true, 'text-embedding-3-large');
        $data = $connector->get_prompt_data('hello world', $this->createMock(request_options::class));
        $this->assertEquals('hello world', $data['input']);
        $this->assertArrayNotHasKey('model', $data);
    }

    /**
     * Tests that execute_prompt_completion() extracts the embedding vector and usage information.
     *
     * @covers ::execute_prompt_completion
     */
    public function test_execute_prompt_completion_extracts_embedding(): void {
        $connector = $this->make_connector('');
        $apiresponse = json_encode([
            'object' => 'list',
            'model' => 'text-embedding-3-small',
            'data' => [
                ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, -0.2, 0.3]],
            ],
            'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5],
        ]);

        $response = $connector->execute_prompt_completion(
            Utils::streamFor($apiresponse),
            $this->createMock(request_options::class)
        );

        $this->assertEquals(200, $response->get_code());
        $this->assertEquals('text-embedding-3-small', $response->get_modelinfo());
        $this->assertEquals([0.1, -0.2, 0.3], json_decode($response->get_content(), true));
        $this->assertEquals(5.0, $response->get_usage()->value);
        $this->assertEquals(5.0, $response->get_usage()->customvalue1);
        $this->assertEquals(0.0, $response->get_usage()->customvalue2);
    }
}


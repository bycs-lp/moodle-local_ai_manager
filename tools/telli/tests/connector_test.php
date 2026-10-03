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

namespace aitool_telli;

use GuzzleHttp\Psr7\Response;
use local_ai_manager\local\connector_factory;
use local_ai_manager\local\unit;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * Tests for Telli
 *
 * @package   aitool_telli
 * @copyright 2025 ISB Bayern
 * @author    Philipp Memmel
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class connector_test extends \advanced_testcase {
    /**
     * Test the constructor.
     *
     * @covers \aitool_telli\connector::__construct
     */
    public function test_constructor(): void {
        $connectorfactory = \core\di::get(connector_factory::class);
        $connector = $connectorfactory->get_connector_by_connectorname_and_model('telli', 'gpt-4o');
        // Assert that the connector is properly set up by acquiring some information that is being fetched from the
        // wrapped connector.
        $this->assertEquals(unit::TOKEN, $connector->get_unit());

        // Initialize the connector without specifying a model.
        $connector = $connectorfactory->get_connector_by_connectorname('telli');
        // If we come to here, it at least worked, which is what we want to test.
        // Accessing methods that require the wrapped connector to be initialized will fail, though.
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('err_modelnotavailable', 'aitool_telli'));
        $connector->get_unit();
    }

    /**
     * Data provider for test_setup_wrapped_connector_uses_baseurl_when_configured.
     *
     * @return array Test cases.
     */
    public static function setup_wrapped_connector_uses_baseurl_when_configured_provider(): array {
        return [
            'chatgpt_model' => [
                'model' => 'gpt-4o',
                'expectedsuffix' => 'v1/chat/completions',
            ],
            'dalle_model' => [
                'model' => 'imagen-4.0-generate-001',
                'expectedsuffix' => 'v1/images/generations',
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('setup_wrapped_connector_uses_baseurl_when_configured_provider')]
    /**
     * Test that a configured baseurl is used as endpoint, with the model specific suffix appended.
     *
     * @covers \aitool_telli\connector::setup_wrapped_connector
     * @param string $model The model name to use.
     * @param string $expectedsuffix The expected endpoint suffix for that model's purpose.
     */
    public function test_setup_wrapped_connector_uses_baseurl_when_configured(string $model, string $expectedsuffix): void {
        $this->resetAfterTest();

        set_config('baseurl', 'https://api.telli-test.com', 'aitool_telli');

        $connectorfactory = \core\di::get(connector_factory::class);
        $connector = $connectorfactory->get_connector_by_connectorname_and_model('telli', $model);

        $this->assertEquals(
            'https://api.telli-test.com/' . $expectedsuffix,
            $connector->get_wrapped_connector()->get_instance()->get_endpoint()
        );
    }

    /**
     * Test that a configured global API key wins over the instance endpoint and API key, without baseurl set.
     *
     * @covers \aitool_telli\connector::setup_wrapped_connector
     */
    public function test_setup_wrapped_connector_globalapikey_with_baseurl_unset(): void {
        $this->resetAfterTest();

        set_config('globalapikey', 'globalapikeyvalue', 'aitool_telli');

        $connectorfactory = \core\di::get(connector_factory::class);

        $instance = $connectorfactory->get_new_instance('telli');
        $instance->set_model_id_from_name('gpt-4o');
        $instance->set_endpoint('https://api.individual_telli.com');
        $instance->set_apikey('instanceapikey');

        $connector = new \aitool_telli\connector($instance);

        // The endpoint should not be used when a global API key is configured.
        $this->assertNull($connector->get_wrapped_connector()->get_instance()->get_endpoint());

        // The global API key should be used instead of the instance API key.
        $this->assertEquals('globalapikeyvalue', $connector->get_wrapped_connector()->get_instance()->get_apikey());
    }

    /**
     * Test that baseurl and global API key both take precedence over the instance's own endpoint and API key,
     * even when both admin settings and instance-specific values are configured at the same time.
     *
     * @covers \aitool_telli\connector::setup_wrapped_connector
     */
    public function test_setup_wrapped_connector_baseurl_and_globalapikey_override_instance_values(): void {
        $this->resetAfterTest();

        set_config('baseurl', 'https://api.telli-test.com', 'aitool_telli');
        set_config('globalapikey', 'globalapikeyvalue', 'aitool_telli');

        $connectorfactory = \core\di::get(connector_factory::class);

        $instance = $connectorfactory->get_new_instance('telli');
        $instance->set_model_id_from_name('gpt-4o');
        $instance->set_endpoint('https://api.individual_telli.com');
        $instance->set_apikey('instanceapikey');

        $connector = new \aitool_telli\connector($instance);

        // The baseurl should be used as endpoint, not the instance's own endpoint.
        $this->assertEquals(
            'https://api.telli-test.com/v1/chat/completions',
            $connector->get_wrapped_connector()->get_instance()->get_endpoint()
        );

        // The global API key should be used, not the instance's own API key.
        $this->assertEquals('globalapikeyvalue', $connector->get_wrapped_connector()->get_instance()->get_apikey());
    }

    /**
     * Test that the instance's own endpoint and API key are used when neither baseurl nor global API key are set.
     *
     * @covers \aitool_telli\connector::setup_wrapped_connector
     */
    public function test_setup_wrapped_connector_uses_instance_endpoint_without_globalapikey(): void {
        $this->resetAfterTest();

        $connectorfactory = \core\di::get(connector_factory::class);

        $instance = $connectorfactory->get_new_instance('telli');
        $instance->set_model_id_from_name('gpt-4o');
        $instance->set_endpoint('https://api.individual_telli.com');
        $instance->set_apikey('instanceapikey');

        $connector = new \aitool_telli\connector($instance);

        // The instance endpoint should be used when no baseurl is configured.
        $this->assertEquals(
            'https://api.individual_telli.com',
            $connector->get_wrapped_connector()->get_instance()->get_endpoint()
        );

        // The instance apikey should be used when no global API key is configured.
        $this->assertEquals('instanceapikey', $connector->get_wrapped_connector()->get_instance()->get_apikey());
    }

    /**
     * Test that the endpoint stays null when neither baseurl nor an instance endpoint are configured.
     *
     * @covers \aitool_telli\connector::setup_wrapped_connector
     */
    public function test_setup_wrapped_connector_no_endpoint_configured(): void {
        $this->resetAfterTest();

        $connectorfactory = \core\di::get(connector_factory::class);
        $connector = $connectorfactory->get_connector_by_connectorname_and_model('telli', 'gpt-4o');

        // The endpoint should be null when no baseurl or instance endpoint is configured.
        $this->assertNull($connector->get_wrapped_connector()->get_instance()->get_endpoint());
    }

    /**
     * Test the get_custom_error_message method with various error responses.
     *
     * @covers       \aitool_telli\connector::get_custom_error_message
     * @dataProvider get_custom_error_message_provider
     * @param int $code The HTTP error code.
     * @param string $responsebody The response body JSON string.
     * @param string $exceptionmessage The exception message.
     * @param string $expectedkey The expected lang string key or empty if no custom message.
     */
    public function test_get_custom_error_message(
        int $code,
        string $responsebody,
        string $exceptionmessage,
        string $expectedkey
    ): void {
        $this->resetAfterTest();
        set_config('availablemodels', "gpt-4o\nimagen-4.0-generate-001#IMGGEN", 'aitool_telli');

        $connectorfactory = \core\di::get(connector_factory::class);
        $connector = $connectorfactory->get_connector_by_connectorname_and_model('telli', 'gpt-4o');

        // Create a mock exception with getResponse method.
        $exception = $this->create_mock_exception($responsebody, $exceptionmessage);

        // Use reflection to call the protected method.
        $reflection = new \ReflectionClass($connector);
        $method = $reflection->getMethod('get_custom_error_message');

        $result = $method->invoke($connector, $code, $exception);

        if (empty($expectedkey)) {
            $this->assertEmpty($result);
        } else {
            $this->assertEquals(get_string($expectedkey, 'aitool_telli'), $result);
        }
    }

    /**
     * Data provider for test_get_custom_error_message.
     *
     * @return array Test cases.
     */
    public static function get_custom_error_message_provider(): array {
        return [
            // 400 errors - only "error" attribute (Imagen content filter).
            'error_400_german_unangemessen' => [
                'code' => 400,
                'responsebody' => '{"error":"Die Anfrage wurde wegen unangemessener Inhalte automatisch blockiert."}',
                'exceptionmessage' => '',
                'expectedkey' => 'err_contentfilter',
            ],
            'error_400_english_blocked' => [
                'code' => 400,
                'responsebody' => '{"error":"Request was blocked due to inappropriate content."}',
                'exceptionmessage' => '',
                'expectedkey' => 'err_contentfilter',
            ],
            'error_400_safety' => [
                'code' => 400,
                'responsebody' => '{"error":"Blocked by safety filter."}',
                'exceptionmessage' => '',
                'expectedkey' => 'err_contentfilter',
            ],
            'error_400_no_match' => [
                'code' => 400,
                'responsebody' => '{"error":"Some other error occurred."}',
                'exceptionmessage' => '',
                'expectedkey' => '',
            ],
            // 500 errors - both "error" and "details" attributes (Azure/OpenAI backends).
            'error_500_details_safety' => [
                'code' => 500,
                'responsebody' => '{"error":"Internal error","details":"Request blocked due to safety concerns."}',
                'exceptionmessage' => '',
                'expectedkey' => 'err_contentfilter',
            ],
            'error_500_error_content_policy' => [
                'code' => 500,
                'responsebody' => '{"error":"Content policy violation detected."}',
                'exceptionmessage' => '',
                'expectedkey' => 'err_contentfilter',
            ],
            'error_500_no_image_data' => [
                'code' => 500,
                'responsebody' => '{}',
                'exceptionmessage' => 'No image data received from API',
                'expectedkey' => 'err_noimagedata',
            ],
            'error_500_no_match' => [
                'code' => 500,
                'responsebody' => '{"error":"Internal server error"}',
                'exceptionmessage' => '',
                'expectedkey' => '',
            ],
        ];
    }

    /**
     * Creates a mock exception with a response containing the given body.
     *
     * @param string $responsebody The JSON response body.
     * @param string $exceptionmessage The exception message.
     * @return ClientExceptionInterface The mock exception.
     */
    private function create_mock_exception(string $responsebody, string $exceptionmessage): ClientExceptionInterface {
        $response = new Response(400, [], $responsebody);

        return new class ($exceptionmessage, $response) extends \Exception implements ClientExceptionInterface {
            /**
             * The response object.
             *
             * @var Response
             */
            private Response $response;

            /**
             * Constructor.
             *
             * @param string $message The exception message.
             * @param Response $response The response object.
             */
            public function __construct(string $message, Response $response) {
                parent::__construct($message);
                $this->response = $response;
            }

            /**
             * Get the response object.
             *
             * @return Response The response object.
             */
            // phpcs:ignore moodle.NamingConventions.ValidFunctionName.LowercaseMethod, moodle.Commenting.MissingDocblock.MissingTestcaseMethodDescription
            public function getResponse(): Response {
                return $this->response;
            }
        };
    }
}

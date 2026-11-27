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

use aitool_telli\local\modelinfo;
use GuzzleHttp\Psr7\Response;
use local_ai_manager\local\connector_factory;
use local_ai_manager\local\unit;
use local_ai_manager\request_options;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * Tests for Telli
 *
 * @package   aitool_telli
 * @copyright 2025 ISB Bayern
 * @author    Philipp Memmel
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\aitool_telli\connector::class)]
final class connector_test extends \advanced_testcase {
    /** @var string Name of the text to speech model created for the tests. */
    private const TTSMODEL = 'phpunit-telli-tts';

    /** @var string Global API key configured for the tests. */
    private const GLOBALAPIKEY = 'globalapikeyvalue';

    /** @var array Voices the mocked model info returns for the text to speech model. */
    private const VOICES = [
        ['key' => 'de-DE-Klaus:MAI', 'displayname' => 'Klaus'],
        ['key' => 'de-DE-Mia:MAI', 'displayname' => 'Mia'],
    ];

    /**
     * Test the constructor.
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
            'gpt_image_generation_model' => [
                'model' => 'gpt-image-1.5',
                'expectedsuffix' => 'v1/images/generations',
            ],
        ];
    }

    /**
     * Test that a configured baseurl is used as endpoint, with the model specific suffix appended.
     *
     * @param string $model The model name to use.
     * @param string $expectedsuffix The expected endpoint suffix for that model's purpose.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('setup_wrapped_connector_uses_baseurl_when_configured_provider')]
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
     */
    public function test_setup_wrapped_connector_no_endpoint_configured(): void {
        $this->resetAfterTest();

        $connectorfactory = \core\di::get(connector_factory::class);
        $connector = $connectorfactory->get_connector_by_connectorname_and_model('telli', 'gpt-4o');

        // The endpoint should be null when no baseurl or instance endpoint is configured.
        $this->assertNull($connector->get_wrapped_connector()->get_instance()->get_endpoint());
    }

    /**
     * Test that a text to speech model is wrapped by the OpenAI TTS connector with the speech endpoint and without temperature.
     */
    public function test_setup_wrapped_connector_tts_model(): void {
        $this->resetAfterTest();
        $this->create_tts_model();

        $connector = $this->create_connector(self::TTSMODEL);

        $this->assertInstanceOf(\aitool_openaitts\connector::class, $connector->get_wrapped_connector());
        $this->assertSame(
            'https://api.telli-test.com/v1/audio/speech',
            $connector->get_wrapped_connector()->get_instance()->get_endpoint()
        );
        $this->assertFalse($connector->has_customvalue1());
    }

    /**
     * Data provider for test_has_customvalue1.
     *
     * @return array Test cases.
     */
    public static function has_customvalue1_provider(): array {
        return [
            'text model' => [
                'model' => 'gpt-4o',
                'expected' => true,
            ],
            'image generation model' => [
                'model' => 'gpt-image-1.5',
                'expected' => false,
            ],
            'text to speech model' => [
                'model' => self::TTSMODEL,
                'expected' => false,
            ],
            'no model' => [
                'model' => null,
                'expected' => true,
            ],
        ];
    }

    /**
     * Test that only text models offer the custom value (temperature).
     *
     * @param string|null $model The model name, null for a connector without model.
     * @param bool $expected Whether the custom value is expected to be offered.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('has_customvalue1_provider')]
    public function test_has_customvalue1(?string $model, bool $expected): void {
        $this->resetAfterTest();
        $this->create_tts_model();
        if ($model === null) {
            $connectorfactory = \core\di::get(connector_factory::class);
            $connector = $connectorfactory->get_connector_by_connectorname('telli');
        } else {
            $connector = $this->create_connector($model);
        }

        $this->assertSame($expected, $connector->has_customvalue1());
    }

    /**
     * Test that a text to speech model offers the voices of the Telli model info as options.
     */
    public function test_get_available_options_tts_model(): void {
        $this->resetAfterTest();
        $this->create_tts_model();
        $modelinfo = $this->createMock(modelinfo::class);
        $modelinfo->expects($this->once())->method('get_voices')
            ->with('https://api.telli-test.com/', self::GLOBALAPIKEY, self::TTSMODEL)
            ->willReturn(self::VOICES);
        \core\di::set(modelinfo::class, $modelinfo);
        $connector = $this->create_connector(self::TTSMODEL);

        $options = $connector->get_available_options();

        $this->assertSame(['voices' => self::VOICES], $options);
    }

    /**
     * Test that the instance API key is passed to the Telli model info if no global API key is set.
     */
    public function test_get_available_options_tts_model_uses_instance_apikey_without_globalapikey(): void {
        $this->resetAfterTest();
        $this->create_tts_model();
        set_config('baseurl', 'https://api.telli-test.com', 'aitool_telli');
        $modelinfo = $this->createMock(modelinfo::class);
        $modelinfo->expects($this->once())->method('get_voices')
            ->with('https://api.telli-test.com/', 'instanceapikey', self::TTSMODEL)
            ->willReturn(self::VOICES);
        \core\di::set(modelinfo::class, $modelinfo);
        $connectorfactory = \core\di::get(connector_factory::class);
        $instance = $connectorfactory->get_new_instance('telli');
        $instance->set_model_id_from_name(self::TTSMODEL);
        $instance->set_apikey('instanceapikey');
        $connector = new \aitool_telli\connector($instance);

        $options = $connector->get_available_options();

        $this->assertSame(['voices' => self::VOICES], $options);
    }

    /**
     * Data provider for test_get_available_options_tts_model_passes_baseurl.
     *
     * @return array
     */
    public static function get_available_options_tts_model_passes_baseurl_provider(): array {
        return [
            'global baseurl without trailing slash' => [
                'baseurl' => 'https://api.telli-test.com',
                'globalapikey' => '',
                'instanceendpoint' => '',
                'expectedbaseurl' => 'https://api.telli-test.com/',
            ],
            'global baseurl with trailing slash' => [
                'baseurl' => 'https://api.telli-test.com/',
                'globalapikey' => '',
                'instanceendpoint' => '',
                'expectedbaseurl' => 'https://api.telli-test.com/',
            ],
            'instance endpoint with text to speech suffix' => [
                'baseurl' => '',
                'globalapikey' => '',
                'instanceendpoint' => 'https://own.example.com/v1/audio/speech',
                'expectedbaseurl' => 'https://own.example.com/',
            ],
            'instance endpoint with text to speech suffix and trailing slash' => [
                'baseurl' => '',
                'globalapikey' => '',
                'instanceendpoint' => 'https://own.example.com/v1/audio/speech/',
                'expectedbaseurl' => 'https://own.example.com/',
            ],
            'instance endpoint without text to speech suffix' => [
                'baseurl' => '',
                'globalapikey' => '',
                'instanceendpoint' => 'https://own.example.com/speech',
                'expectedbaseurl' => '',
            ],
            'global API key without baseurl ignores instance endpoint' => [
                'baseurl' => '',
                'globalapikey' => 'globalkey',
                'instanceendpoint' => 'https://own.example.com/v1/audio/speech',
                'expectedbaseurl' => '',
            ],
        ];
    }

    /**
     * Test that the base URL passed to the Telli model info is derived from the endpoint of the wrapped connector.
     *
     * @param string $baseurl the configured base URL
     * @param string $globalapikey the configured global API key
     * @param string $instanceendpoint the endpoint of the Telli instance
     * @param string $expectedbaseurl the base URL expected to be passed to the model info
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('get_available_options_tts_model_passes_baseurl_provider')]
    public function test_get_available_options_tts_model_passes_baseurl(
        string $baseurl,
        string $globalapikey,
        string $instanceendpoint,
        string $expectedbaseurl
    ): void {
        $this->resetAfterTest();
        $this->create_tts_model();
        set_config('baseurl', $baseurl, 'aitool_telli');
        set_config('globalapikey', $globalapikey, 'aitool_telli');
        $modelinfo = $this->createMock(modelinfo::class);
        $modelinfo->expects($this->once())->method('get_voices')
            ->with($expectedbaseurl, $this->anything(), self::TTSMODEL)
            ->willReturn(self::VOICES);
        \core\di::set(modelinfo::class, $modelinfo);
        $connectorfactory = \core\di::get(connector_factory::class);
        $instance = $connectorfactory->get_new_instance('telli');
        $instance->set_model_id_from_name(self::TTSMODEL);
        $instance->set_apikey('instanceapikey');
        $instance->set_endpoint($instanceendpoint);
        $connector = new \aitool_telli\connector($instance);

        $connector->get_available_options();
    }

    /**
     * Test that a text model offers no options and does not ask the Telli model info.
     */
    public function test_get_available_options_text_model(): void {
        $this->resetAfterTest();
        $modelinfo = $this->createMock(modelinfo::class);
        $modelinfo->expects($this->never())->method('get_voices');
        \core\di::set(modelinfo::class, $modelinfo);
        $connector = $this->create_connector('gpt-4o');

        $options = $connector->get_available_options();

        $this->assertSame([], $options);
    }

    /**
     * Data provider for test_get_prompt_data_tts_model.
     *
     * @return array Datasets with the request options and the expected voice in the prompt data.
     */
    public static function get_prompt_data_tts_model_provider(): array {
        return [
            'selected_voice' => [
                'requestoptions' => ['voices' => ['de-DE-Mia:MAI']],
                'expectedvoice' => 'de-DE-Mia:MAI',
            ],
            'no_voice_selected' => [
                'requestoptions' => [],
                'expectedvoice' => 'de-DE-Klaus:MAI',
            ],
        ];
    }

    /**
     * Test that the selected voice is sent and that the first voice of the model info is used if no voice is selected.
     *
     * @param array $requestoptions the options of the request
     * @param string $expectedvoice the voice expected in the prompt data
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('get_prompt_data_tts_model_provider')]
    public function test_get_prompt_data_tts_model(array $requestoptions, string $expectedvoice): void {
        $this->resetAfterTest();
        $this->create_tts_model();
        $modelinfo = $this->createMock(modelinfo::class);
        $modelinfo->method('get_voices')->willReturn(self::VOICES);
        \core\di::set(modelinfo::class, $modelinfo);
        $requestoptionsobject = $this->createMock(request_options::class);
        $requestoptionsobject->method('get_options')->willReturn($requestoptions);
        $connector = $this->create_connector(self::TTSMODEL);

        $promptdata = $connector->get_prompt_data('Hallo Welt', $requestoptionsobject);

        $this->assertSame('Hallo Welt', $promptdata['input']);
        $this->assertSame(self::TTSMODEL, $promptdata['model']);
        $this->assertSame($expectedvoice, $promptdata['voice']);
    }

    /**
     * Test that a text to speech request without any available voice is rejected instead of sending the OpenAI default voice.
     */
    public function test_get_prompt_data_tts_model_without_voices(): void {
        $this->resetAfterTest();
        $this->create_tts_model();
        $modelinfo = $this->createMock(modelinfo::class);
        $modelinfo->method('get_voices')->willReturn([]);
        \core\di::set(modelinfo::class, $modelinfo);
        $requestoptions = $this->createMock(request_options::class);
        $requestoptions->method('get_options')->willReturn([]);
        $connector = $this->create_connector(self::TTSMODEL);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('err_novoices', 'aitool_telli'));
        $connector->get_prompt_data('Hallo Welt', $requestoptions);
    }

    /**
     * Data provider for test_get_prompt_data_tts_model_not_configured.
     *
     * @return array
     */
    public static function get_prompt_data_tts_model_not_configured_provider(): array {
        return [
            'no API key' => [
                'baseurl' => 'https://api.telli-test.com',
                'instanceapikey' => '',
                'instanceendpoint' => '',
            ],
            'no base URL derivable from the instance endpoint' => [
                'baseurl' => '',
                'instanceapikey' => 'instanceapikey',
                'instanceendpoint' => 'https://own.example.com/speech',
            ],
        ];
    }

    /**
     * Test that a text to speech request without API key or base URL is rejected as configuration error.
     *
     * @param string $baseurl the configured base URL
     * @param string $instanceapikey the API key of the Telli instance
     * @param string $instanceendpoint the endpoint of the Telli instance
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('get_prompt_data_tts_model_not_configured_provider')]
    public function test_get_prompt_data_tts_model_not_configured(
        string $baseurl,
        string $instanceapikey,
        string $instanceendpoint
    ): void {
        $this->resetAfterTest();
        $this->create_tts_model();
        set_config('baseurl', $baseurl, 'aitool_telli');
        set_config('globalapikey', '', 'aitool_telli');
        $modelinfo = $this->createMock(modelinfo::class);
        $modelinfo->method('get_voices')->willReturn([]);
        \core\di::set(modelinfo::class, $modelinfo);
        $requestoptions = $this->createMock(request_options::class);
        $requestoptions->method('get_options')->willReturn([]);
        $connectorfactory = \core\di::get(connector_factory::class);
        $instance = $connectorfactory->get_new_instance('telli');
        $instance->set_model_id_from_name(self::TTSMODEL);
        $instance->set_apikey($instanceapikey);
        $instance->set_endpoint($instanceendpoint);
        $connector = new \aitool_telli\connector($instance);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('err_ttsnotconfigured', 'aitool_telli'));
        $connector->get_prompt_data('Hallo Welt', $requestoptions);
    }

    /**
     * Test that the configuration check for text to speech does not affect text models.
     */
    public function test_get_prompt_data_text_model_ignores_tts_configuration(): void {
        $this->resetAfterTest();
        $requestoptions = $this->createMock(request_options::class);
        $requestoptions->method('get_options')->willReturn([]);
        $connector = $this->create_connector('gpt-4o');

        $promptdata = $connector->get_prompt_data('Hallo Welt', $requestoptions);

        $this->assertArrayNotHasKey('voice', $promptdata);
    }

    /**
     * Test the get_custom_error_message method with various error responses.
     *
     * @param int $code The HTTP error code.
     * @param string $responsebody The response body JSON string.
     * @param string $exceptionmessage The exception message.
     * @param string $expectedkey The expected lang string key or empty if no custom message.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('get_custom_error_message_provider')]
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
     * Creates the text to speech model which is not part of the shipped models.
     */
    private function create_tts_model(): void {
        /** @var \local_ai_manager_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_ai_manager');
        $generator->create_model([
            'name' => self::TTSMODEL,
            'textgeneration' => 0,
            'tts' => 1,
            'connectors' => ['telli'],
        ]);
    }

    /**
     * Creates a Telli connector for the given model with configured base URL and global API key.
     *
     * @param string $modelname the name of the model the connector should use
     * @return connector the Telli connector
     */
    private function create_connector(string $modelname): connector {
        set_config('baseurl', 'https://api.telli-test.com', 'aitool_telli');
        set_config('globalapikey', self::GLOBALAPIKEY, 'aitool_telli');
        $connectorfactory = \core\di::get(connector_factory::class);
        return $connectorfactory->get_connector_by_connectorname_and_model('telli', $modelname);
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

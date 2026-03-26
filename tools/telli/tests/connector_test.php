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
        $this->resetAfterTest();
        set_config('availablemodels', "gpt-4o\nimagen-4.0-generate-001#IMGGEN", 'aitool_telli');

        $connectorfactory = \core\di::get(connector_factory::class);
        $connector = $connectorfactory->get_connector_by_connectorname_and_model('telli', 'gpt-4o');
        // Assert that the connector is properly set up by acquiring some information that is being fetched from the
        // wrapped connector.
        $this->assertEquals(unit::TOKEN, $connector->get_unit());

        // Initialize the connector without specifying a model.
        $connector = $connectorfactory->get_connector_by_connectorname('telli');
        // If we come to here, it at least worked, which is what we want to test.
        // Accessing methods that require the wrapped connector to be initialized will fail, though.
        $this->expectException(\Error::class);
        $this->expectExceptionMessage(
            'Typed property aitool_telli\connector::$wrappedconnector must not be accessed before initialization'
        );
        $connector->get_unit();
    }

    /**
     * Test the get_custom_error_message method with various error responses.
     *
     * @covers \aitool_telli\connector::get_custom_error_message
     * @dataProvider error_message_provider
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
    public static function error_message_provider(): array {
        return [
            'telli imagen direct error string - german' => [
                400,
                '{"error":"Die Anfrage wurde wegen unangemessener Inhalte automatisch blockiert."}',
                '',
                'err_contentfilter',
            ],
            'telli imagen direct error string - blocked' => [
                400,
                '{"error":"Request was blocked due to inappropriate content."}',
                '',
                'err_contentfilter',
            ],
            'safety in details' => [
                400,
                '{"error": {"code": "error"}, "details": "The request was blocked due to safety concerns."}',
                '',
                'err_contentfilter',
            ],
            'content policy in error message' => [
                400,
                '{"error": {"code": "error", "message": "Image generation blocked due to content policy violation."}}',
                '',
                'err_contentfilter',
            ],
            'blocked in error message' => [
                400,
                '{"error": {"code": "error", "message": "Request was blocked for inappropriate content."}}',
                '',
                'err_contentfilter',
            ],
            'inappropriate in error message' => [
                400,
                '{"error": {"code": "error", "message": "The prompt contains inappropriate material."}}',
                '',
                'err_contentfilter',
            ],
            'violates in error message' => [
                400,
                '{"error": {"code": "error", "message": "This request violates our usage policies."}}',
                '',
                'err_contentfilter',
            ],
            'safety in error message' => [
                400,
                '{"error": {"code": "error", "message": "Blocked by safety filter."}}',
                '',
                'err_contentfilter',
            ],
            'no image data error 500' => [
                500,
                '{}',
                'No image data received from API',
                'err_noimagedata',
            ],
            'generic 400 error' => [
                400,
                '{"error": {"code": "error", "message": "Some other error occurred."}}',
                '',
                '',
            ],
            'generic 500 error' => [
                500,
                '{}',
                'Internal server error',
                '',
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

        $exception = new class ($exceptionmessage, $response) extends \Exception implements ClientExceptionInterface {
            /**
             * The response object.
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

        return $exception;
    }
}

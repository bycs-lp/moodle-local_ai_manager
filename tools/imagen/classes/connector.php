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

namespace aitool_imagen;

use local_ai_manager\base_connector;
use local_ai_manager\base_purpose;
use local_ai_manager\local\aitool_option_vertexai_authhandler;
use local_ai_manager\local\prompt_response;
use local_ai_manager\local\request_response;
use local_ai_manager\local\unit;
use local_ai_manager\local\usage;
use local_ai_manager\request_options;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Connector for Imagen.
 *
 * @package    aitool_imagen
 * @copyright  2024 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class connector extends base_connector {
    /** @var string The access token to use for authentication against the Google imagen API endpoint. */
    private string $accesstoken = '';

    #[\Override]
    public function get_models_by_purpose(): array {
        $modelsbypurpose = base_purpose::get_installed_purposes_array();
        $modelsbypurpose['imggen'] = [
            'imagen-3.0-generate-002',
            'imagen-4.0-generate-001',
            'imagen-4.0-ultra-generate-001',
            'imagen-4.0-fast-generate-001',
        ];
        return $modelsbypurpose;
    }

    #[\Override]
    public function get_prompt_data(string $prompttext, request_options $requestoptions): array {
        $options = $requestoptions->get_options();
        $promptdata = [
            'instances' => [
                [
                    'prompt' => $prompttext,
                ],
            ],
            'parameters' => [
                'sampleCount' => 1,
                'safetySetting' => 'block_few',
                'language' => 'en',
                'aspectRatio' => $options['sizes'][0],
            ],
        ];

        return $promptdata;
    }

    #[\Override]
    protected function get_headers(): array {
        $headers = parent::get_headers();
        $headers['Authorization'] = 'Bearer ' . $this->accesstoken;
        return $headers;
    }

    #[\Override]
    public function get_unit(): unit {
        return unit::COUNT;
    }

    #[\Override]
    public function make_request(array $data, request_options $requestoptions): request_response {
        $vertexaiauthhandler =
            new aitool_option_vertexai_authhandler($this->instance->get_id(), $this->instance->get_customfield1());
        try {
            // Composing the "Authorization" header is not that easy as just looking up a Bearer token in the database.
            // So we here explicitly retrieve the access token from cache or the Google OAuth API and do some proper error handling.
            // After we stored it in $this->accesstoken it can be properly set into the header by the self::get_headers method.
            $this->accesstoken = $vertexaiauthhandler->get_access_token();
        } catch (\moodle_exception $exception) {
            return request_response::create_from_error(0, $exception->getMessage(), $exception->getTraceAsString());
        }
        $requestresponse = parent::make_request($data, $requestoptions);
        // We keep track of the time the cached access token expires. However, due latency, different clocks
        // on different servers etc. we could end up sending a request with an actually expired access token.
        // In this case we refresh our access token and re-submit the request ONE TIME.
        if ($vertexaiauthhandler->is_expired_accesstoken_reason_for_failing($requestresponse)) {
            try {
                $vertexaiauthhandler->refresh_access_token();
            } catch (\moodle_exception $exception) {
                return request_response::create_from_error(0, $exception->getMessage(), $exception->getTraceAsString());
            }
            $requestresponse = parent::make_request($data, $requestoptions);
        }
        return $requestresponse;
    }

    #[\Override]
    public function execute_prompt_completion(StreamInterface $result, request_options $requestoptions): prompt_response {
        global $USER;
        $options = $requestoptions->get_options();
        $content = json_decode($result->getContents(), true);
        if (empty($content['predictions']) || !array_key_exists('bytesBase64Encoded', $content['predictions'][0])) {
            return prompt_response::create_from_error(
                400,
                get_string('err_predictionmissing', 'aitool_imagen'),
                ''
            );
        }
        $fs = get_file_storage();
        $fileinfo = [
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $options['itemid'],
            'filepath' => '/',
            'filename' => $options['filename'],
        ];
        $file = $fs->create_file_from_string($fileinfo, base64_decode($content['predictions'][0]['bytesBase64Encoded']));

        $filepath = \moodle_url::make_draftfile_url(
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename()
        )->out();

        return prompt_response::create_from_result($this->instance->get_model(), new usage(1.0), $filepath);
    }

    #[\Override]
    public function get_available_options(): array {
        $options['sizes'] = [
            ['key' => '1:1', 'displayname' => '1:1 (1024 x 1024)'],
            ['key' => '3:4', 'displayname' => '4:3 (896 x 1280)'],
            ['key' => '4:3', 'displayname' => '4:3 (1280 x 896)'],
            ['key' => '9:16', 'displayname' => '9:16 (768 x 1408)'],
            ['key' => '16:9', 'displayname' => '16:9 (1408 x 768)'],
        ];
        return $options;
    }

    #[\Override]
    protected function get_custom_error_message(int $code, ?ClientExceptionInterface $exception = null): string {
        $message = '';
        switch ($code) {
            case 400:
                if (method_exists($exception, 'getResponse') && !empty($exception->getResponse())) {
                    $responsebody = json_decode($exception->getResponse()->getBody()->getContents());
                    if (
                        property_exists($responsebody, 'error')
                        && property_exists($responsebody->error, 'status')
                        && $responsebody->error->status === 'INVALID_ARGUMENT'
                    ) {
                        $message = get_string('err_contentpolicyviolation', 'aitool_imagen');
                    }
                }
                break;
        }
        return $message;
    }
}

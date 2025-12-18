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

use local_ai_manager\ai_manager_utils;
use local_ai_manager\base_connector;
use local_ai_manager\local\aitool_option_azure;
use local_ai_manager\local\connector_factory;
use local_ai_manager\local\prompt_response;
use local_ai_manager\local\unit;
use local_ai_manager\local\usage;
use local_ai_manager\request_options;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Connector for ChatGPT.
 *
 * @package    aitool_chatgpt
 * @copyright  ISB Bayern, 2024
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class connector extends \local_ai_manager\base_connector {
    #[\Override]
    public function get_models_by_purpose(): array {
        $chatgptmodels =
            ['gpt-3.5-turbo', 'gpt-4-turbo', 'gpt-4o', 'gpt-4o-mini', 'o1', 'o1-mini', 'o3', 'o3-mini', 'o4-mini'];
        $modelsbypurpose = [
            'chat' => $chatgptmodels,
            'feedback' => $chatgptmodels,
            'singleprompt' => $chatgptmodels,
            'translate' => $chatgptmodels,
            'tts' => [],
            'imggen' => [],
            'itt' => ['gpt-4-turbo', 'gpt-4o', 'gpt-4o-mini', 'o1', 'o3', 'o4-mini'],
            'questiongeneration' => $chatgptmodels,
        ];
        foreach ($modelsbypurpose as $purpose => $models) {
            $modelsbypurpose[$purpose][] = aitool_option_azure::get_azure_model_name('chatgpt');
        }
        return $modelsbypurpose;
    }

    #[\Override]
    public function get_selectable_models(): array {
        return array_filter($this->get_models(), fn($model) => aitool_option_azure::get_azure_model_name('chatgpt') !== $model);
    }

    #[\Override]
    public function get_unit(): unit {
        return unit::TOKEN;
    }

    #[\Override]
    public function execute_prompt_completion(StreamInterface $result, request_options $requestoptions): prompt_response {
        // phpcs:disable moodle.Commenting.TodoComment.MissingInfoInline
        /* TODO error handling: check if answer contains "stop", then the LLM will have successfully done something.
            If not, we need to do some error handling and return prompt_response::create_from_error(...
        */
        // phpcs:enable moodle.Commenting.TodoComment.MissingInfoInline
        $content = json_decode($result->getContents(), true);

        return prompt_response::create_from_result(
            $content['model'],
            new usage(
                (float) $content['usage']['total_tokens'],
                (float) $content['usage']['prompt_tokens'],
                (float) $content['usage']['completion_tokens']
            ),
            $content['choices'][0]['message']['content']
        );
    }

    #[\Override]
    public function get_prompt_data(string $prompttext, request_options $requestoptions): array {
        $options = $requestoptions->get_options();
        $messages = [];
        if (array_key_exists('conversationcontext', $options)) {
            foreach ($options['conversationcontext'] as $message) {
                switch ($message['sender']) {
                    case 'user':
                        $role = 'user';
                        break;
                    case 'ai':
                        $role = 'assistant';
                        break;
                    case 'system':
                        $role = 'system';
                        break;
                    default:
                        throw new \moodle_exception('exception_badmessageformat', 'local_ai_manager');
                }
                $messages[] = [
                    'role' => $role,
                    'content' => $message['message'],
                ];
            }
            $messages[] = ['role' => 'user', 'content' => $prompttext];
        } else if (array_key_exists('image', $options)) {
            $messages[] = [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $prompttext,
                    ],
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => $options['image'],
                        ],
                    ],
                ],
            ];
        } else {
            $messages[] = ['role' => 'user', 'content' => $prompttext];
        }

        $parameters = [
            'messages' => $messages,
        ];
        if (!in_array($this->get_instance()->get_model(), ['o1', 'o1-mini', 'o3', 'o3-mini', 'o4-mini'])) {
            $parameters['temperature'] = $this->instance->get_temperature();
        }
        if (!$this->instance->azure_enabled()) {
            // If azure is enabled, the model will be preconfigured in the azure resource, so we do not need to send it.
            $parameters['model'] = $this->instance->get_model();
        }
        return $parameters;
    }

    #[\Override]
    public function has_customvalue1(): bool {
        return true;
    }

    #[\Override]
    public function has_customvalue2(): bool {
        return true;
    }

    #[\Override]
    protected function get_headers(): array {
        $headers = parent::get_headers();
        if (!$this->instance->azure_enabled()) {
            // If azure is not enabled, we just use the default headers for the OpenAI API.
            return $headers;
        }
        if (in_array('Authorization', array_keys($headers))) {
            unset($headers['Authorization']);
            $headers['api-key'] = $this->instance->get_apikey();
        }
        return $headers;
    }

    #[\Override]
    public function allowed_mimetypes(): array {
        return ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];
    }

    #[\Override]
    protected function get_custom_error_message(int $code, ?ClientExceptionInterface $exception = null): string {
        $message = '';
        switch ($code) {
            case 400:
                if (
                    method_exists($exception, 'getResponse')
                    && !empty($exception->getResponse())
                ) {
                    $responsebody = json_decode($exception->getResponse()->getBody()->getContents());
                    if (
                        property_exists($responsebody, 'error')
                        && property_exists($responsebody->error, 'code')
                        && $responsebody->error->code === 'content_filter'
                    ) {
                        $message = get_string('err_contentfilter', 'aitool_chatgpt');
                    }
                }
                break;
        }
        return $message;
    }
}

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
    /** @var string Default OpenAI Chat Completions endpoint. */
    public const DEFAULT_OPENAI_COMPLETIONS_ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    /**
     * Returns the list of model names that do not support the temperature parameter.
     *
     * These are reasoning models which use reasoning_effort instead of temperature.
     *
     * @return array list of model name strings
     */
    public function get_no_temperature_models(): array {
        return [
            'o1', 'o1-mini', 'o1-preview', 'o1-pro', 'o3', 'o3-mini', 'o3-pro', 'o4-mini',
            'gpt-5.5', 'gpt-5.5-pro',
            'gpt-5.4', 'gpt-5.4-pro', 'gpt-5.4-mini', 'gpt-5.4-nano',
            'gpt-5', 'gpt-5-pro', 'gpt-5-mini', 'gpt-5-nano',
        ];
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
        // Reasoning models do not support the temperature parameter.
        $modelname = $this->instance->get_model_name();
        if (!in_array($modelname, $this->get_no_temperature_models())) {
            $parameters['temperature'] = $this->instance->get_temperature();
        }
        if (!$this->instance->azure_enabled()) {
            // If azure is enabled, the model will be preconfigured in the azure resource, so we do not need to send it.
            $parameters['model'] = $this->instance->get_model_name();
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
            $headers['api-key'] = $this->get_api_key();
        }
        return $headers;
    }

    #[\Override]
    protected function get_endpoint_url(): string {
        return $this->instance->get_endpoint() ?: self::DEFAULT_OPENAI_COMPLETIONS_ENDPOINT;
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

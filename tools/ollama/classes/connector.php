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

namespace aitool_ollama;

use local_ai_manager\local\prompt_response;
use local_ai_manager\local\unit;
use local_ai_manager\local\usage;
use local_ai_manager\request_options;
use Psr\Http\Message\StreamInterface;

/**
 * Connector for Ollama.
 *
 * @package    aitool_ollama
 * @copyright  ISB Bayern, 2024
 * @author     Stefan Hanauska <stefan.hanauska@csg-in.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class connector extends \local_ai_manager\base_connector {

    #[\Override]
    public function get_models_by_purpose(): array {
        $visionmodels =
                ['llava-llama3', 'llava-phi3', 'granite-3.2-vision', 'bakllava', 'moondream', 'llama3.2-vision', 'llama4', 'gemma3',
                        'qwen2.5vl', 'mistral-small3.1'];
        $textmodels =
                ['gemma', 'gemma3', 'llama3', 'llama3.1', 'llama3.2-vision', 'llama3.3', 'llama4', 'phi4', 'mistral',
                        'mistral-small3.1', 'codellama', 'qwen', 'mixtral', 'dolphin-mixtral', 'tinyllama'];
        return [
                'chat' => $textmodels,
                'feedback' => $textmodels,
                'singleprompt' => $textmodels,
                'translate' => $textmodels,
                'itt' => $visionmodels,
                'questiongeneration' => $textmodels,
        ];
    }

    #[\Override]
    public function get_unit(): unit {
        return unit::TOKEN;
    }

    #[\Override]
    public function execute_prompt_completion(StreamInterface $result, request_options $requestoptions): prompt_response {

        $content = json_decode($result->getContents(), true);

        // On cached results there is no prompt token count in the response.
        $prompttokencount = isset($content['prompt_eval_count']) ? $content['prompt_eval_count'] : 0.0;
        $responsetokencount = isset($content['eval_count']) ? $content['eval_count'] : 0.0;
        $totaltokencount = $prompttokencount + $responsetokencount;

        return prompt_response::create_from_result($content['model'],
                new usage($totaltokencount, $prompttokencount, $prompttokencount),
                $content['message']['content']);
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
                    'content' => $prompttext,
                    'images' => [explode(',', $options['image'])[1]],
            ];
        } else {
            $messages[] = ['role' => 'user', 'content' => $prompttext];
        }
        $data = [
                'model' => $this->instance->get_model(),
                'messages' => $messages,
                'stream' => false,
                'keep_alive' => '60m',
                'options' => [
                        'temperature' => $this->instance->get_temperature(),
                ],
        ];
        return $data;
    }

    #[\Override]
    public function allowed_mimetypes(): array {
        return ['image/png', 'image/jpg', 'image/jpeg'];
    }
}

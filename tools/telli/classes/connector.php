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

use local_ai_manager\base_connector;
use local_ai_manager\base_instance;
use local_ai_manager\local\connector_factory;
use local_ai_manager\local\prompt_response;
use local_ai_manager\local\unit;
use local_ai_manager\request_options;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Connector for the AIS API.
 *
 * @package    aitool_telli
 * @copyright  2025 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class connector extends base_connector {

    /** @var base_connector The wrapped connector this connector is passing everything to. */
    private base_connector $wrappedconnector;

    /**
     * Set up the wrapper connector and inject all necessary information.
     *
     * @param base_instance $instance the telli instance
     */
    public function __construct(base_instance $instance) {
        parent::__construct($instance);
        $this->setup_wrapped_connector($instance);
    }

    #[\Override]
    public function get_models_by_purpose(): array {
        $models = [];
        $visionmodels = [];
        $imggenmodels = [];
        $availablemodelssetting = get_config('aitool_telli', 'availablemodels');
        foreach (explode("\n", $availablemodelssetting) as $model) {
            $model = trim($model);
            if (str_ends_with($model, '#IMGGEN')) {
                $model = trim(preg_replace('/#IMGGEN$/', '', $model));
                $imggenmodels[] = $model;
            } else if (str_ends_with($model, '#VISION')) {
                $model = trim(preg_replace('/#VISION$/', '', $model));
                $visionmodels[] = $model;
                $models[] = $model;
            } else {
                $models[] = $model;
            }
        }

        asort($models);
        asort($visionmodels);

        return [
                'chat' => $models,
                'feedback' => $models,
                'singleprompt' => $models,
                'translate' => $models,
                'tts' => [],
                'itt' => $visionmodels,
                'imggen' => $imggenmodels,
                'questiongeneration' => $models,
        ];
    }

    #[\Override]
    public function get_unit(): unit {
        return $this->wrappedconnector->get_unit();
    }

    #[\Override]
    protected function get_api_key(): string {
        return $this->wrappedconnector->instance->get_apikey();
    }

    #[\Override]
    protected function get_endpoint_url(): string {
        return $this->wrappedconnector->get_endpoint_url();
    }

    #[\Override]
    public function has_customvalue1(): bool {
        if (in_array($this->instance->get_model(), $this->get_models_by_purpose()['imggen'])) {
            return false;
        } else {
            return true;
        }
    }

    #[\Override]
    public function get_prompt_data(string $prompttext, request_options $requestoptions): array {
        return $this->wrappedconnector->get_prompt_data($prompttext, $requestoptions);
    }

    #[\Override]
    public function execute_prompt_completion(StreamInterface $result, request_options $requestoptions): prompt_response {
        return $this->wrappedconnector->execute_prompt_completion($result, $requestoptions);
    }

    #[\Override]
    public function get_available_options(): array {
        // The endpoint v1/chat/completions does not support any options anyway, but also v1/images/generations of the Telli API
        // does not support any options like for example sizes which the OpenAI endpoint does, so we have to disable options here.
        return [];
    }

    #[\Override]
    protected function get_custom_error_message(int $code, ?ClientExceptionInterface $exception = null): string {
        $message = '';
        switch ($code) {
            case 400:
                if (method_exists($exception, 'getResponse') && !empty($exception->getResponse())) {
                    $responsebody = json_decode($exception->getResponse()->getBody()->getContents());
                    if (property_exists($responsebody, 'error')) {
                        if (property_exists($responsebody, 'details')) {
                            // We have no proper specific error code if the safety system rejects the prompt. So we have to
                            // do some fishing for strings in the detailed error message. If we don't succeed a general error
                            // message will be displayed by the manager itself.
                            if (str_contains(mb_strtolower($responsebody->details), 'safety')) {
                                $message = get_string('err_contentfilter', 'aitool_telli');
                            }
                        }
                    }
                }
                break;
        }
        return $message;
    }

    /**
     * Sets up the wrapped connector object.
     *
     * To be called from the constructor.
     *
     * Further explanation of this pattern: The Telli API is basically using OpenAI endpoints and API structure.
     * That's why we are using the following pattern here to avoid code duplication and stability.
     * Depending on the endpoint being used (LLM, image generation, ...), we are using the corresponding OpenAI connector and
     * use the aitool_telli connector purely as a wrapper for it.
     * When creating the connector object $wrappedconnector an empty instance is also being created by
     * \local_ai_manager\local\connector_factory::get_connector_by_connectorname which can be accessed by
     * $wrappedconnector->instance. We pass all the information of the aitool_telli instance to this wrapped instance
     * (which is never being persisted) and implement most of the functions in the aitool_telli connector in that way that they
     * only pass the call to the wrapped connector.
     *
     * @param base_instance $instance the telli instance
     */
    private function setup_wrapped_connector(base_instance $instance): void {
        $connectorfactory = \core\di::get(connector_factory::class);
        if (!$instance->record_exists()) {
            return;
        }
        if (in_array($instance->get_model(), $this->get_models_by_purpose()['imggen'])) {
            $this->wrappedconnector = $connectorfactory->get_connector_by_connectorname('dalle');
            $endpointsuffix = 'v1/images/generations';
        } else {
            $this->wrappedconnector = $connectorfactory->get_connector_by_connectorname('chatgpt');
            $endpointsuffix = 'v1/chat/completions';
            // If there is a temperature parameter, pass it.
            $this->wrappedconnector->instance->set_customfield1($this->instance->get_customfield1());
        }
        // Pass the model to the wrapped instance.
        $this->wrappedconnector->instance->set_model($instance->get_model());
        // Set the endpoint.
        $baseurl = get_config('aitool_telli', 'baseurl');
        if (!empty($baseurl)) {
            if (!str_ends_with($baseurl, '/')) {
                $baseurl .= '/';
            }
            $this->wrappedconnector->instance->set_endpoint($baseurl . $endpointsuffix);
        } else {
            $this->wrappedconnector->instance->set_endpoint($this->instance->get_endpoint());
        }
        // Set the api key.
        $globalapikey = get_config('aitool_telli', 'globalapikey');
        $this->wrappedconnector->instance->set_apikey(!empty($globalapikey) ? $globalapikey : $this->instance->get_apikey());
    }
}

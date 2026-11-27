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

use aipurpose_embedding\purpose;
use local_ai_manager\base_connector;
use local_ai_manager\local\prompt_response;
use local_ai_manager\local\unit;
use local_ai_manager\local\usage;
use local_ai_manager\request_options;
use Psr\Http\Message\StreamInterface;

/**
 * Connector for OpenAI embeddings.
 *
 * @package    aitool_openaiembedding
 * @copyright  2026 ISB Bayern
 * @author     GitHub Copilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class connector extends base_connector {
    /** @var string Default OpenAI embeddings endpoint. */
    public const DEFAULT_OPENAI_EMBEDDINGS_ENDPOINT = 'https://api.openai.com/v1/embeddings';

    #[\Override]
    public function get_unit(): unit {
        return unit::TOKEN;
    }

    #[\Override]
    public function get_prompt_data(string $prompttext, request_options $requestoptions): array {
        $prompttexts = $this->get_prompttexts($requestoptions);
        if ($prompttexts !== null && $prompttext !== '') {
            throw new \coding_exception('The prompt text must be empty if the option ' . purpose::OPTION_PROMPTTEXTS . ' is set');
        }
        $parameters = [
            // The API accepts a list of texts as input and returns one embedding per text.
            'input' => $prompttexts ?? $prompttext,
        ];
        if (!$this->instance->azure_enabled()) {
            // If azure is enabled, the model will be preconfigured in the azure resource, so we do not need to send it.
            $parameters['model'] = $this->instance->get_model_name();
        }
        return $parameters;
    }

    #[\Override]
    public function execute_prompt_completion(StreamInterface $result, request_options $requestoptions): prompt_response {
        $rawcontent = $result->getContents();
        $content = json_decode($rawcontent, true);

        $prompttexts = $this->get_prompttexts($requestoptions);
        if ($prompttexts === null) {
            $embeddings = $content['data'][0]['embedding'] ?? [];
            $valid = !empty($embeddings);
        } else {
            // The API returns the embeddings with the position of their text, not necessarily in the order of the texts.
            $embeddings = array_column($content['data'] ?? [], 'embedding', 'index');
            ksort($embeddings);
            $valid = array_keys($embeddings) === array_keys($prompttexts)
                && count(array_filter($embeddings)) === count($prompttexts);
            $embeddings = array_values($embeddings);
        }

        if (!$valid) {
            // A missing or empty embedding means the API did not return a usable result, which is an error.
            return prompt_response::create_from_error(
                400,
                get_string('error_emptyembedding', 'aitool_openaiembedding'),
                $rawcontent
            );
        }

        // Embeddings only report prompt and total tokens, there are no completion tokens.
        return prompt_response::create_from_result(
            $content['model'] ?? $this->instance->get_model_name(),
            new usage(
                (float) ($content['usage']['total_tokens'] ?? 0),
                (float) ($content['usage']['prompt_tokens'] ?? 0),
                0.0
            ),
            json_encode($embeddings)
        );
    }

    /**
     * Returns the prompt texts to embed in one request, if the request has the option {@see purpose::OPTION_PROMPTTEXTS}.
     *
     * @param request_options $requestoptions the request options of the request
     * @return ?array the list of prompt texts, or null for a request embedding the single prompt text
     */
    private function get_prompttexts(request_options $requestoptions): ?array {
        return $requestoptions->get_options()[purpose::OPTION_PROMPTTEXTS] ?? null;
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
        return $this->instance->get_endpoint() ?: self::DEFAULT_OPENAI_EMBEDDINGS_ENDPOINT;
    }
}



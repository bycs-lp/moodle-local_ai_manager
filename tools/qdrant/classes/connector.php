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
namespace aitool_qdrant;

use core\http_client;
use local_ai_manager\local\prompt_response;
use local_ai_manager\local\request_response;
use local_ai_manager\local\unit;
use local_ai_manager\local\usage;
use local_ai_manager\request_options;
use Locale;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Client\ClientExceptionInterface;

class connector extends \local_ai_manager\base_connector {

    #[\Override]
    public function get_models_by_purpose(): array {
        return [
                'rag' => ['text-embedding-small3']
        ];
    }

    #[\Override]
    public function get_unit(): unit {
        return unit::COUNT;
    }

    public function make_request(array $data, request_options $requestoptions): request_response
    {
        $client = new http_client([
            'timeout' => get_config('local_ai_manager', 'requesttimeout'),
            'verify' => !empty(get_config('local_ai_manager', 'verifyssl')),
        ]);

        $action = $requestoptions->get_options()['action'] ?? 'retrieve';
        $payloadfunc = "get_payload_$action";
        $options['headers'] = $this->get_headers();
        $options['body'] = json_encode($this->$payloadfunc($data, $requestoptions));

        [$method, $endpoint] = $this->get_endpoint($this->instance->get_endpoint(), $action);
        try {
            $response = $client->request($method, $endpoint, $options);
        } catch (ClientExceptionInterface $exception) {
            return $this->create_error_response_from_exception($exception);
        }
        if ($response->getStatusCode() === 200) {
            $return = request_response::create_from_result($response->getBody());
        } else {
            $return = request_response::create_from_error(
                    $response->getStatusCode(),
                    get_string('error_sendingrequestfailed', 'local_ai_manager'),
                    $response->getBody()->getContents(),
                    $response->getBody()
            );
        }
        return $return;
    }

    /**
     * Generate API Payload for a "retrieve" action.
     */
    protected function get_payload_retrieve($data, $requestoptions): array {
        $embedding = $this->get_embedding($data['content']);
        $payload = [
            'using' => $this->get_vector_name(),
            'query' => array_map(
                function($item) { 
                    return (float)$item; 
                }, 
                $embedding
            ),
            'top' => $data['topk'] ?? 1,
            'with_payload' => true,
            'with_vector' => false,
        ];
        return $payload;
    }
    /**
     * Generate API Payload for a "store" action.
     */
    protected function get_payload_store($data, $requestoptions): array {
        
        $embedding = $this->get_embedding($data['content']);        
        $id = $this->make_guid();

        $payload = [
            'points' => [
                [
                    'id' => "{$id}",
                    'vector' => [
                        $this->get_vector_name() => array_map(
                            function($item) { 
                                return (float)$item; 
                            }, 
                            $embedding
                        )
                    ],
                    'payload' => [
                        'content' => $data['content'],
                        'document' => (object) ($data['document'] ?? []),
                        'metadata' => (object) ($requestoptions->get_options()['metadata'] ?? []),
                    ]
                ]
            ],
        ];
        return $payload;
    }

    /**
     * Get embedding for text (with caching).
     */
    protected function get_embedding($datatoembed): array {
        $usecache = false;
        $cache = \cache::make('local_ai_manager', 'textembeddingmodels');
        $cachekey = md5($datatoembed);
        if ($usecache && $cache->has($cachekey)) {
            $embedding = $cache->get($cachekey);
        } else {    
            // We have to re-use ai manager to get vector.
            $txmanager = new \local_ai_manager\manager('embedding');
            $response = $txmanager->perform_request(
                $datatoembed ?? "",
                'aitool_qdrant',
                \core\context\system::instance()->id,
            );
            $embedding = trim($response->get_content());
            $cache->set($cachekey, $embedding);
        }
        return explode(",", $embedding);
    }
    /**
     * Generate GUID.
     */
    protected function make_guid() {
        $charid = strtoupper(md5(uniqid(rand(), true)));
        $hyphen = chr(45);// "-"
        $uuid = chr(123)// "{"
            .substr($charid, 0, 8).$hyphen
            .substr($charid, 8, 4).$hyphen
            .substr($charid,12, 4).$hyphen
            .substr($charid,16, 4).$hyphen
            .substr($charid,20,12)
            .chr(125);// "}"
        return $uuid;
    }
    /**
     * Work out dynamic endpoint.
     */
    protected function get_endpoint($instancendpoint, $action): array {
        $method = 'GET';
        switch ($action) {
            case 'store':
                $method = "PUT";
                $instancendpoint .= 'collections/' . $this->get_collection_name() . '/points?wait=true';
                break;
            case 'retrieve':
                $method = 'POST';
                $instancendpoint .= 'collections/' . $this->get_collection_name() . '/points/query';
                break;
            default:
                throw new \moodle_exception('error_invalidaction', 'local_ai_manager', '', $action);
        }
        return [$method, $instancendpoint];
    }

    protected function get_collection_name(): string {
        return "moodle";
    }
    protected function get_vector_name(): string {
        return "contentvector";
    }

    #[\Override]
    public function execute_prompt_completion(StreamInterface $result, request_options $requestoptions): prompt_response {
        $content = $result->getContents();
        return prompt_response::create_from_result(
            $this->instance->get_model(),
            new usage(1.0),
            $content
        );
    }

    /**
     * @param string $prompttext The text prompt to process. This is expected to be the document content 
     */
     #[\Override]
    public function get_prompt_data(string $prompttext, request_options $requestoptions): array {
        
        $prompt['action'] = $requestoptions->get_options()['action'] ?? 'retrieve';
        if ($prompt['action'] === 'retrieve') {
            $prompt['content'] = $prompttext;
            $prompt['topk'] = $requestoptions->get_options()['topk'] ?? 1;
        }
        if ($prompt['action'] === 'store') {
            $prompt['content'] = $prompttext;
        }
        
        return $prompt;
    }
}
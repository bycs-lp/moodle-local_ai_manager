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
        debugging("connector::make_request");
        print_r($data);
        print_r($requestoptions);
     
        $client = new http_client([
            'timeout' => get_config('local_ai_manager', 'requesttimeout'),
            'verify' => !empty(get_config('local_ai_manager', 'verifyssl')),
        ]);

        $action = $data['action'] ?? '';
        $payloadfunc = "get_payload_$action";
        $options['headers'] = $this->get_headers();
        $options['body'] = json_encode($this->$payloadfunc($data));

        [$method, $endpoint] = $this->get_endpoint($this->instance->get_endpoint(), $action);
        var_dump($method);
        var_dump($endpoint);
        var_dump($options);
        
        try {
            $response = $client->request($method, $endpoint, $options);
            // $response = $client->post($endpoint, $options);
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
    protected function get_payload_retrieve($data): array {
        $embedding = $this->get_embedding($data);
        $payload = [
            // 'query' => [
            //     $this->get_vector_name() => array_map(
            //         function($item) { 
            //             return (float)$item; 
            //         }, 
            //         $embedding
            //     )
            // ],
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
    protected function get_payload_store($data): array {
        $embedding = $this->get_embedding($data);
        
        $id = $this->make_guid();
        $payload = [
            'points' => [
                [
                    'id' => "{$id}",
                    'vector' => [
                        $this->get_vector_name() => array_map(
                            function($item) { return (float)$item; }, explode(',', $embedding)
                            )
                    ],
                    'payload' => $data['metadata'] ?? new \stdClass(),
                ]
            ],
        ];
        return $payload;
    }

    /**
     * Get embedding for text (with caching).
     */
    protected function get_embedding($data): array {
        $usecache = true;
        $cache = \cache::make('local_ai_manager', 'textembeddingmodels');
        $cachekey = md5($data['content']);
        if ($usecache && $cache->has($cachekey)) {
            $embedding = $cache->get($cachekey);
            debugging('Using cached embedding');
        } else {    
            // We have to re-use ai manager to get vector.
            debugging("Fetching new embedding from 'embedding' connector");
            $txmanager = new \local_ai_manager\manager('embedding');

            $response = $txmanager->perform_request(
                $data['content'],
                'aitool_qdrant',
                \core\context\system::instance()->id,
            );
            var_dump($response);
            $embedding = trim($response->get_content());

            var_dump($embedding);
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

        print_r($result);
        print_r($requestoptions);
        $content = (string)$result->getContents();
        return prompt_response::create_from_result(
            $this->instance->get_model(),
            new usage(1.0),
            $content
        );
    }

     #[\Override]
    public function get_prompt_data(string $prompttext, request_options $requestoptions): array {
        print_r($prompttext);
        print_r($requestoptions);

        $prompt = json_decode($prompttext, true);
        print_r($prompt);

        return $prompt;
    }
}
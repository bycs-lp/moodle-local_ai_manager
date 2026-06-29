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
    const INITIALISATION_OK = 0;
    const INITIALISATION_ERROR_FAILED_TO_CREATE_COLLECTION = 1;
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

    /** 
     * @var bool $initialized Indicates if the back end initialisation check has been performed
     * **and succeeded**.
     */
    static protected $initialized = false;
    /**
     * This function is called to initialise the back end if needed, for instance creating a collection.
     */
    public function initialise() : void {
        mtrace("Initialising Qdrant connector...");
        
        $client = new http_client([
            'timeout' => (int)get_config('local_ai_manager', 'requesttimeout'),
            'verify' => !empty(get_config('local_ai_manager', 'verifyssl')),
        ]);
        
        // Step 1: GET request to list collections
        $response = $client->request('GET', $this->instance->get_endpoint() . 'collections');
        if ($response->getStatusCode() !== 200) {
            throw new \moodle_exception('error_failedtogetcollections', 'aitool_qdrant');
        }
        
        $body = json_decode($response->getBody()->getContents());
        
        // Step 2: Check if our collection exists
        $collectionExists = false;
        $targetCollection = $this->get_collection_name();
        
        if (!empty($body->result->collections)) {
            foreach ($body->result->collections as $collection) {
                if ($collection->name === $targetCollection) {
                    $collectionExists = true;
                    mtrace("Collection '{$targetCollection}' already exists.");
                    break;
                }
            }
        }
        
        // Step 3: If collection doesn't exist, create it
        if (!$collectionExists) {
            mtrace("Collection '{$targetCollection}' not found, creating it...");
            
            $createPayload = [
                'vectors' => [
                    $this->get_vector_name() => [
                        'size' => 1536,
                        'distance' => 'Cosine',
                    ],
                ],
            ];
            
            $response = $client->request('PUT', $this->instance->get_endpoint() . 'collections/' . $targetCollection, [
                'body' => json_encode($createPayload),
            ]);
            
            if ($response->getStatusCode() !== 200) {
                set_config('failedtoinitialise', self::INITIALISATION_ERROR_FAILED_TO_CREATE_COLLECTION, 'aitool_qdrant');
                throw new \moodle_exception('error_failedtocreatecollection', 'aitool_qdrant');
            }
            
            mtrace("Collection '{$targetCollection}' created successfully.");
        }
        self::$initialized = true;
    }

    public function make_request(array $data, request_options $requestoptions): request_response
    {
        $failedtoinitialise = get_config('aitool_qdrant', 'failedtoinitialise');
        if ($failedtoinitialise) {  // Anything other than a 0 is a failure and this can't work until fixed.
            throw new \moodle_exception('error_failedtoinitialise'.$failedtoinitialise, 'aitool_qdrant');
        }
        if (!self::$initialized) {
            // We only check initialisation once per request lifecycle.
            $this->initialise();
        }
    
        $client = new http_client([
            'timeout' => (int)get_config('local_ai_manager', 'requesttimeout'),
            'verify' => !empty(get_config('local_ai_manager', 'verifyssl')),
        ]);

        $action = $requestoptions->get_options()['action'] ?? 'retrieve';
        $payloadfunc = "get_payload_$action";
        $options['headers'] = $this->get_headers();
        $options['body'] = json_encode($this->$payloadfunc($data, $requestoptions));

        [$method, $endpoint] = $this->get_endpoint($this->instance->get_endpoint(), $action);
        mtrace("Attempting to send Qdrant request to endpoint: {$endpoint}");
        // mtrace("With payload: " . $options['body']["points"][0]["payload"]);
    
        try {
            $response = $client->request($method, $endpoint, $options);
        } catch (ClientExceptionInterface $exception) {
            mtrace($exception->getMessage());
            return $this->create_error_response_from_exception($exception);
        }
        if ($response->getStatusCode() === 200) {
            mtrace("Qdrant request successful.");
            $return = request_response::create_from_result($response->getBody());
        } else {
            mtrace("Qdrant request failed with status code: " . $response->getStatusCode());
            mtrace("Response body: " . $response->getBody()->getContents());
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

    protected function generate_uuid($data = null) {
        // Generate 16 bytes (128 bits) of random data or use the data passed into the function.
        $data = $data ?? random_bytes(16);
        // Set version to 0100
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        // Set bits 6-7 to 10
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        // Output the 36 character UUID.
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Generate API Payload for a "store" action.
     */
    protected function get_payload_store($data, $requestoptions): array {
        $embedding = $this->get_embedding($data['content']);        
        $id = $this->generate_uuid($requestoptions->get_options()['metadata']['id']);
        
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
                        // 'document' => (object) ($data['document'] ?? []),    // This seems to be an unnecessary duplication of what is in the "content" field.
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
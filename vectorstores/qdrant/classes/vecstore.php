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

namespace aivecstore_qdrant;

use core\http_client;
use local_ai_manager\base_vecstore;
use local_ai_manager\collection_not_found_exception;
use local_ai_content\local\enriched_vector;
use local_ai_manager\local\vecstore_query_response;
use local_ai_manager\local\vecstore_response;

/**
 * Vector store implementation for the Qdrant vector database.
 *
 * Talks to a Qdrant server over its REST API using the connection details (endpoint, API key,
 * distance metric) of the configured {@see base_vecstore_instance}.
 *
 * @package    aivecstore_qdrant
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class vecstore extends base_vecstore {
    #[\Override]
    public function is_available(): vecstore_response {
        // Listing collections requires the server to be reachable and the API key (if any) to be valid.
        $response = $this->request('GET', '/collections');
        if ($response['status'] === 200) {
            return vecstore_response::create_from_result();
        }
        return $this->create_error_response($response, 'Could not connect to qdrant endpoint.');
    }

    #[\Override]
    public function create_collection(): vecstore_response {
        $body = [
            'vectors' => [
                'size' => (int) $this->instance->get_dimensions(),
                'distance' => $this->map_distance(),
            ],
        ];
        $response = $this->request('PUT', '/collections/' . rawurlencode($this->get_collection()), $body);
        if ($response['status'] === 200) {
            return vecstore_response::create_from_result();
        }
        return $this->create_error_response($response, 'Could not create qdrant collection.');
    }

    #[\Override]
    public function delete_collection(): vecstore_response {
        $response = $this->request('DELETE', '/collections/' . rawurlencode($this->get_collection()));
        if ($response['status'] === 200) {
            return vecstore_response::create_from_result();
        }
        return $this->create_error_response($response, 'Could not delete qdrant collection.');
    }

    #[\Override]
    protected function store_embeddings(array $embeddings): vecstore_response {
        $points = [];
        foreach ($embeddings as $embedding) {
            $vector = json_decode($embedding->get_vector(), true);
            if (!is_array($vector) || empty($vector)) {
                continue;
            }
            $points[] = [
                // Qdrant requires a point id; we generate a UUID as callers do not manage record references.
                'id' => \core\uuid::generate(),
                'vector' => array_values($vector),
                'payload' => [
                    'content' => $embedding->get_content(),
                    'sourceid' => $embedding->get_sourceid(),
                    'chunk' => $embedding->get_chunk(),
                    'maxchunks' => $embedding->get_maxchunks(),
                ],
            ];
        }
        return $this->with_existing_collection(function () use ($points): vecstore_response {
            $path = '/collections/' . rawurlencode($this->get_collection()) . '/points?wait=true';
            $response = $this->request('PUT', $path, ['points' => $points]);
            if ($response['status'] === 404) {
                throw new collection_not_found_exception();
            }
            if ($response['status'] === 200) {
                return vecstore_response::create_from_result();
            }
            return $this->create_error_response($response, 'Could not store embeddings in qdrant collection.');
        });
    }

    #[\Override]
    public function query(array $vector, int $topk = 5, array $filters = []): vecstore_response {
        $body = [
            'vector' => array_values($vector),
            'limit' => $topk,
            'with_payload' => true,
            'with_vector' => true,
        ];
        if (!empty($filters)) {
            $must = [];
            foreach ($filters as $key => $value) {
                // An array value matches any of the given values (IN semantics); a scalar matches exactly.
                $must[] = is_array($value)
                    ? ['key' => $key, 'match' => ['any' => array_values($value)]]
                    : ['key' => $key, 'match' => ['value' => $value]];
            }
            $body['filter'] = ['must' => $must];
        }
        return $this->with_existing_collection(function () use ($body): vecstore_response {
            $response = $this->request('POST', '/collections/' . rawurlencode($this->get_collection()) . '/points/search', $body);
            if ($response['status'] === 404) {
                throw new collection_not_found_exception();
            }
            if ($response['status'] !== 200) {
                return $this->create_error_response($response, 'Could not run qdrant similarity query.');
            }
            if (empty($response['data']['result'])) {
                return vecstore_response::create_from_query_result(vecstore_query_response::create_from_empty_result());
            }
            $matches = [];
            foreach ($response['data']['result'] as $hit) {
                $payload = $hit['payload'] ?? [];
                $matches[] = enriched_vector::create(
                    isset($hit['vector']) ? json_encode(array_values($hit['vector'])) : '',
                    (string) ($payload['content'] ?? ''),
                    (int) ($payload['sourceid'] ?? 0),
                    (int) ($payload['chunk'] ?? 0),
                    (int) ($payload['maxchunks'] ?? 0)
                );
            }
            return vecstore_response::create_from_query_result(vecstore_query_response::create_from_result($matches));
        });
    }

    #[\Override]
    public function get_all(): vecstore_response {
        $vectors = [];
        $offset = null;
        do {
            $body = ['limit' => 100, 'with_payload' => true, 'with_vector' => true];
            if (!is_null($offset)) {
                $body['offset'] = $offset;
            }
            $response = $this->request('POST', '/collections/' . rawurlencode($this->get_collection()) . '/points/scroll', $body);
            if ($response['status'] !== 200) {
                return $this->create_error_response($response, 'Could not fetch vectors from qdrant collection.');
            }
            if (empty($response['data']['result']['points'])) {
                break;
            }
            foreach ($response['data']['result']['points'] as $point) {
                $payload = $point['payload'] ?? [];
                $vectors[] = enriched_vector::create(
                    isset($point['vector']) ? json_encode(array_values($point['vector'])) : '',
                    (string) ($payload['content'] ?? ''),
                    (int) ($payload['sourceid'] ?? 0),
                    (int) ($payload['chunk'] ?? 0),
                    (int) ($payload['maxchunks'] ?? 0)
                );
            }
            $offset = $response['data']['result']['next_page_offset'] ?? null;
        } while (!is_null($offset));
        return vecstore_response::create_from_query_result(vecstore_query_response::create_from_result($vectors));
    }

    #[\Override]
    public function delete_embeddings(int $sourceid): vecstore_response {
        $path = '/collections/' . rawurlencode($this->get_collection()) . '/points/delete?wait=true';
        $body = [
            'filter' => [
                'must' => [
                    ['key' => 'sourceid', 'match' => ['value' => $sourceid]],
                ],
            ],
        ];
        $response = $this->request('POST', $path, $body);
        if ($response['status'] === 200) {
            return vecstore_response::create_from_result();
        }
        return $this->create_error_response($response, 'Could not delete embeddings from qdrant collection.');
    }

    /**
     * Creates a standardized error response from a qdrant request payload.
     *
     * @param array $response the response array from {@see self::request()}
     * @param string $fallbackmessage fallback error message when qdrant does not provide one
     * @return vecstore_response the standardized error response
     */
    protected function create_error_response(array $response, string $fallbackmessage): vecstore_response {
        $code = (int) ($response['status'] ?? 500);
        if ($code === 200) {
            $code = 500;
        }
        if ($code <= 0) {
            $code = 500;
        }
        $errormessage = (string) ($response['data']['status']['error'] ?? $fallbackmessage);
        $debuginfo = json_encode($response['data']);
        if ($debuginfo === false) {
            $debuginfo = '';
        }
        return vecstore_response::create_from_error($code, $errormessage, $debuginfo);
    }

    /**
     * Maps the instance's distance metric to the value expected by the Qdrant API.
     *
     * @return string the Qdrant distance name
     */
    protected function map_distance(): string {
        return match ($this->get_distance_metric()) {
            base_vecstore::DISTANCE_DOT => 'Dot',
            base_vecstore::DISTANCE_EUCLIDEAN => 'Euclid',
            default => 'Cosine',
        };
    }

    /**
     * Performs a request against the configured Qdrant endpoint.
     *
     * @param string $method the HTTP method (GET, PUT, POST, DELETE)
     * @param string $path the path (including leading slash and any query string) appended to the endpoint
     * @param ?array $body optional payload that will be JSON-encoded into the request body
     * @return array ['status' => int HTTP status (0 on connection failure), 'data' => mixed decoded JSON body or null]
     */
    protected function request(string $method, string $path, ?array $body = null): array {
        $endpoint = rtrim((string) $this->instance->get_endpoint(), '/');
        if (empty($endpoint)) {
            return ['status' => 0, 'data' => null];
        }

        $client = new http_client([
            'timeout' => (int) (get_config('local_ai_manager', 'requesttimeout') ?: 30),
            'verify' => !empty(get_config('local_ai_manager', 'verifyssl')),
            'http_errors' => false,
            // The endpoint is configured by the operator (not a user-supplied URL), and Qdrant commonly
            // runs on a private host / non-standard port (6333) that Moodle's SSRF protection blocks by
            // default. Bypass that check for this trusted, admin-configured connection.
            'ignoresecurity' => true,
        ]);

        $options = ['headers' => $this->get_headers()];
        if (!is_null($body)) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = json_encode($body);
        }

        try {
            $response = $client->request($method, $endpoint . $path, $options);
        } catch (\Throwable $e) {
            return ['status' => 0, 'data' => null];
        }

        return [
            'status' => $response->getStatusCode(),
            'data' => json_decode($response->getBody()->getContents(), true),
        ];
    }

    /**
     * Builds the request headers, including the Qdrant API key when one is configured.
     *
     * @return array the headers
     */
    protected function get_headers(): array {
        $headers = [];
        $apikey = $this->instance->get_apikey();
        if (!empty($apikey)) {
            $headers['api-key'] = $apikey;
        }
        return $headers;
    }
}

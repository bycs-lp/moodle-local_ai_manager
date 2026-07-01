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
    public function is_available(): bool {
        // Listing collections requires the server to be reachable and the API key (if any) to be valid.
        return $this->request('GET', '/collections')['status'] === 200;
    }

    #[\Override]
    public function create_collection(string $collection, int $dimensions): bool {
        $body = [
            'vectors' => [
                'size' => $dimensions,
                'distance' => $this->map_distance(),
            ],
        ];
        return $this->request('PUT', '/collections/' . rawurlencode($collection), $body)['status'] === 200;
    }

    #[\Override]
    public function delete_collection(string $collection): bool {
        return $this->request('DELETE', '/collections/' . rawurlencode($collection))['status'] === 200;
    }

    #[\Override]
    public function upsert_embeddings(string $collection, array $embeddings): bool {
        $points = [];
        foreach ($embeddings as $embedding) {
            $point = [
                'id' => $embedding['id'],
                'vector' => array_values($embedding['vector']),
            ];
            if (!empty($embedding['payload'])) {
                $point['payload'] = $embedding['payload'];
            }
            $points[] = $point;
        }
        $path = '/collections/' . rawurlencode($collection) . '/points?wait=true';
        return $this->request('PUT', $path, ['points' => $points])['status'] === 200;
    }

    #[\Override]
    public function query(string $collection, array $vector, int $topk = 5, array $filters = []): array {
        $body = [
            'vector' => array_values($vector),
            'limit' => $topk,
            'with_payload' => true,
        ];
        if (!empty($filters)) {
            $must = [];
            foreach ($filters as $key => $value) {
                $must[] = ['key' => $key, 'match' => ['value' => $value]];
            }
            $body['filter'] = ['must' => $must];
        }
        $response = $this->request('POST', '/collections/' . rawurlencode($collection) . '/points/search', $body);
        if ($response['status'] !== 200 || empty($response['data']['result'])) {
            return [];
        }
        $matches = [];
        foreach ($response['data']['result'] as $hit) {
            $matches[] = [
                'id' => $hit['id'] ?? null,
                'score' => $hit['score'] ?? null,
                'payload' => $hit['payload'] ?? [],
            ];
        }
        return $matches;
    }

    #[\Override]
    public function delete_embeddings(string $collection, array $ids): bool {
        $path = '/collections/' . rawurlencode($collection) . '/points/delete?wait=true';
        return $this->request('POST', $path, ['points' => array_values($ids)])['status'] === 200;
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

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

namespace aivecstore_postgres;

use local_ai_manager\base_vecstore;
use local_ai_manager\collection_not_found_exception;
use local_ai_content\local\enriched_vector;
use local_ai_manager\local\vecstore_query_response;
use local_ai_manager\local\vecstore_response;

/**
 * Vector store implementation backed by PostgreSQL using the pgvector extension.
 *
 * Connects to an external PostgreSQL database using the libpq connection string configured on the
 * {@see base_vecstore_instance} (stored in its masked apikey field). A "collection" maps to a table
 * with an `id text`, an `embedding vector(N)` and a `payload jsonb` column.
 *
 * @package    aivecstore_postgres
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class vecstore extends base_vecstore {
    /** @var \PgSql\Connection|resource|false|null The cached PostgreSQL connection (null = not yet attempted). */
    protected $connection = null;

    /** @var string The last connection error message captured during {@see self::get_connection()}. */
    protected string $connectionerror = '';

    #[\Override]
    public function is_available(): vecstore_response {
        $connection = $this->get_connection();
        if (!$connection) {
            return $this->create_connection_error_response();
        }
        if (pg_query($connection, 'SELECT 1') === false) {
            return $this->create_error_response(503, 'Could not run postgres vecstore health query.',
                pg_last_error($connection));
        }
        return vecstore_response::create_from_result();
    }

    #[\Override]
    public function create_collection(): vecstore_response {
        $connection = $this->get_connection();
        if (!$connection) {
            return $this->create_connection_error_response();
        }
        // The vector type requires the pgvector extension; create it if the user is allowed to.
        pg_query($connection, 'CREATE EXTENSION IF NOT EXISTS vector');
        $table = pg_escape_identifier($connection, $this->get_collection());
        $sql = "CREATE TABLE IF NOT EXISTS {$table} "
            . '(id text PRIMARY KEY, embedding vector(' . (int) $this->instance->get_dimensions() . '), payload jsonb)';
        if (pg_query($connection, $sql) === false) {
            return $this->create_error_response(500, 'Could not create postgres vecstore collection table.',
                pg_last_error($connection));
        }
        return vecstore_response::create_from_result();
    }

    #[\Override]
    public function delete_collection(): vecstore_response {
        $connection = $this->get_connection();
        if (!$connection) {
            return $this->create_connection_error_response();
        }
        $table = pg_escape_identifier($connection, $this->get_collection());
        if (pg_query($connection, "DROP TABLE IF EXISTS {$table}") === false) {
            return $this->create_error_response(500, 'Could not delete postgres vecstore collection table.',
                pg_last_error($connection));
        }
        return vecstore_response::create_from_result();
    }

    #[\Override]
    protected function store_embeddings(array $embeddings): vecstore_response {
        return $this->with_existing_collection(function () use ($embeddings): vecstore_response {
            $connection = $this->get_connection();
            if (!$connection) {
                return $this->create_connection_error_response();
            }
            if (!$this->table_exists()) {
                throw new collection_not_found_exception();
            }
            $table = pg_escape_identifier($connection, $this->get_collection());
            $sql = "INSERT INTO {$table} (id, embedding, payload) VALUES ($1, $2::vector, $3::jsonb)";

            $success = pg_query($connection, 'BEGIN') !== false;
            foreach ($embeddings as $embedding) {
                $payload = [
                    'content' => $embedding->get_content(),
                    'contextid' => $embedding->get_contextid(),
                    'chunk' => $embedding->get_chunk(),
                    'maxchunks' => $embedding->get_maxchunks(),
                ];
                $params = [
                    // The id column is the table's primary key; we generate a UUID as callers do not manage references.
                    \core\uuid::generate(),
                    $this->normalize_vector_literal($embedding->get_vector()),
                    json_encode($payload),
                ];
                if (@pg_query_params($connection, $sql, $params) === false) {
                    $success = false;
                    break;
                }
            }
            pg_query($connection, $success ? 'COMMIT' : 'ROLLBACK');
            if (!$success) {
                return $this->create_error_response(500, 'Could not store embeddings in postgres vecstore collection.',
                    pg_last_error($connection));
            }
            return vecstore_response::create_from_result();
        });
    }

    #[\Override]
    public function query(array $vector, int $topk = 5, array $filters = []): vecstore_response {
        return $this->with_existing_collection(function () use ($vector, $topk, $filters): vecstore_response {
            $connection = $this->get_connection();
            if (!$connection) {
                return $this->create_connection_error_response();
            }
            if (!$this->table_exists()) {
                throw new collection_not_found_exception();
            }
            $table = pg_escape_identifier($connection, $this->get_collection());
            [$operator, $scoreexpr] = $this->distance_sql();

            // Parameter $1 is reserved for the query vector; filter values are bound from $2 onwards.
            // Keys and values are parameterized (payload->>$k) so arbitrary filter keys can't inject SQL.
            $params = [$this->vector_to_literal($vector)];
            $conditions = [];
            foreach ($filters as $key => $value) {
                $params[] = (string) $key;
                $keyparam = '$' . count($params);
                if (is_array($value)) {
                    if (empty($value)) {
                        // An empty value set matches nothing.
                        $conditions[] = 'FALSE';
                        continue;
                    }
                    // Array value → IN semantics.
                    $placeholders = [];
                    foreach (array_values($value) as $item) {
                        $params[] = (string) $item;
                        $placeholders[] = '$' . count($params);
                    }
                    $conditions[] = "payload->>{$keyparam} IN (" . implode(', ', $placeholders) . ')';
                } else {
                    // Scalar value → exact match.
                    $params[] = (string) $value;
                    $valueparam = '$' . count($params);
                    $conditions[] = "payload->>{$keyparam} = {$valueparam}";
                }
            }
            $where = empty($conditions) ? '' : ('WHERE ' . implode(' AND ', $conditions));

            $sql = "SELECT id, payload, embedding::text AS vector, {$scoreexpr} AS score FROM {$table} {$where} "
                . "ORDER BY embedding {$operator} \$1::vector ASC LIMIT " . (int) $topk;

            $result = @pg_query_params($connection, $sql, $params);
            if ($result === false) {
                return $this->create_error_response(500, 'Could not run postgres vecstore similarity query.',
                    pg_last_error($connection));
            }
            $rows = pg_fetch_all($result);
            if (!is_array($rows)) {
                return vecstore_response::create_from_query_result(vecstore_query_response::create_from_empty_result());
            }
            $matches = [];
            foreach ($rows as $row) {
                $payload = is_null($row['payload']) ? [] : json_decode($row['payload'], true);
                $matches[] = enriched_vector::create(
                    (string) ($row['vector'] ?? ''),
                    (string) ($payload['content'] ?? ''),
                    (int) ($payload['contextid'] ?? 0),
                    (int) ($payload['chunk'] ?? 0),
                    (int) ($payload['maxchunks'] ?? 0)
                );
            }
            return vecstore_response::create_from_query_result(vecstore_query_response::create_from_result($matches));
        });
    }

    #[\Override]
    public function get_all(): vecstore_response {
        $connection = $this->get_connection();
        if (!$connection) {
            return $this->create_connection_error_response();
        }
        if (!$this->table_exists()) {
            return vecstore_response::create_from_query_result(vecstore_query_response::create_from_empty_result());
        }
        $table = pg_escape_identifier($connection, $this->get_collection());
        $result = @pg_query($connection, "SELECT id, payload, embedding::text AS vector FROM {$table}");
        if ($result === false) {
            return $this->create_error_response(500, 'Could not fetch vectors from postgres vecstore collection.',
                pg_last_error($connection));
        }
        $rows = pg_fetch_all($result);
        if (!is_array($rows)) {
            return vecstore_response::create_from_query_result(vecstore_query_response::create_from_empty_result());
        }
        $vectors = [];
        foreach ($rows as $row) {
            $payload = is_null($row['payload']) ? [] : json_decode($row['payload'], true);
            $vectors[] = enriched_vector::create(
                (string) ($row['vector'] ?? ''),
                (string) ($payload['content'] ?? ''),
                (int) ($payload['contextid'] ?? 0),
                (int) ($payload['chunk'] ?? 0),
                (int) ($payload['maxchunks'] ?? 0)
            );
        }
        return vecstore_response::create_from_query_result(vecstore_query_response::create_from_result($vectors));
    }

    #[\Override]
    public function delete_embeddings(int $contextid): vecstore_response {
        $connection = $this->get_connection();
        if (!$connection) {
            return $this->create_connection_error_response();
        }
        if (!$this->table_exists()) {
            return vecstore_response::create_from_result();
        }
        $table = pg_escape_identifier($connection, $this->get_collection());
        $sql = "DELETE FROM {$table} WHERE payload @> \$1::jsonb";
        if (@pg_query_params($connection, $sql, [json_encode(['contextid' => $contextid])]) === false) {
            return $this->create_error_response(500, 'Could not delete embeddings from postgres vecstore collection.',
                pg_last_error($connection));
        }
        return vecstore_response::create_from_result();
    }

    /**
     * Creates a standardized error response for postgres vecstore operations.
     *
     * @param int $code the error code
     * @param string $errormessage the error message
     * @param string $debuginfo optional debug info
     * @return vecstore_response the standardized error response
     */
    protected function create_error_response(int $code, string $errormessage, string $debuginfo = ''): vecstore_response {
        return vecstore_response::create_from_error($code, $errormessage, $debuginfo);
    }

    /**
     * Creates a standardized error response for a connection failure, deriving the appropriate status code
     * from the connection error message captured during {@see self::get_connection()}.
     *
     * The codes follow HTTP semantics:
     * - 400 Bad Request: no connection string configured
     * - 401 Unauthorized: authentication or password failure
     * - 503 Service Unavailable: backend not reachable
     *
     * @return vecstore_response the standardized connection error response
     */
    protected function create_connection_error_response(): vecstore_response {
        $dsn = trim((string) $this->instance->get_apikey());
        if (empty($dsn)) {
            return $this->create_error_response(400, 'No PostgreSQL connection string configured.');
        }
        if (preg_match('/\b(authentication|password|pg_hba)\b/i', $this->connectionerror)) {
            return $this->create_error_response(401, 'PostgreSQL authentication failed.', $this->connectionerror);
        }
        return $this->create_error_response(503, 'Could not connect to postgres vecstore backend.', $this->connectionerror);
    }

    /**
     * Returns the pgvector distance operator and the (similarity) score expression for the configured metric.
     *
     * The score is normalised so that a higher value always means "more similar", to match the other backends.
     *
     * @return array{0: string, 1: string} the [operator, scoreexpr] pair
     */
    protected function distance_sql(): array {
        return match ($this->get_distance_metric()) {
            base_vecstore::DISTANCE_DOT => ['<#>', '(embedding <#> $1::vector) * -1'],
            base_vecstore::DISTANCE_EUCLIDEAN => ['<->', '(embedding <-> $1::vector) * -1'],
            default => ['<=>', '1 - (embedding <=> $1::vector)'],
        };
    }

    /**
     * Converts a numeric vector to the textual representation pgvector expects, e.g. "[1,0.5,0]".
     *
     * @param array $vector the embedding vector
     * @return string the pgvector text literal
     */
    protected function vector_to_literal(array $vector): string {
        return '[' . implode(',', array_map(static fn($v): string => (string) (float) $v, array_values($vector))) . ']';
    }

    /**
     * Normalizes the string representation of an embedding vector into a valid pgvector literal.
     *
     * Enriched vectors carry their embedding as a string (typically a JSON encoded array of floats). If the string
     * is a JSON array it is re-encoded into the pgvector literal format; otherwise it is assumed to already be a
     * valid pgvector literal and returned unchanged.
     *
     * @param string $vector the string representation of the embedding vector
     * @return string the pgvector text literal
     */
    protected function normalize_vector_literal(string $vector): string {
        $decoded = json_decode($vector, true);
        if (is_array($decoded)) {
            return $this->vector_to_literal($decoded);
        }
        return $vector;
    }

    /**
     * Lazily opens (and caches) the PostgreSQL connection from the instance's libpq connection string.
     *
     * On failure the libpq error message is stored in {@see self::$connectionerror} so that
     * {@see self::create_connection_error_response()} can derive a meaningful status code.
     *
     * @return \PgSql\Connection|resource|false the connection, or false if it could not be established
     */
    protected function get_connection() {
        if ($this->connection !== null) {
            return $this->connection;
        }
        $dsn = trim((string) $this->instance->get_apikey());
        if (empty($dsn)) {
            $this->connection = false;
            return false;
        }
        $connection = @pg_connect($dsn);
        if ($connection === false) {
            $this->connectionerror = pg_last_error() ?: 'Connection failed.';
            $this->connection = false;
            return false;
        }
        $this->connection = $connection;
        return $this->connection;
    }

    /**
     * Checks whether the table backing the configured collection exists.
     *
     * @return bool true if the table exists
     */
    protected function table_exists(): bool {
        $connection = $this->get_connection();
        if (!$connection) {
            return false;
        }
        $result = @pg_query_params(
            $connection,
            'SELECT to_regclass($1) AS oid',
            [pg_escape_identifier($connection, $this->get_collection())]
        );
        if ($result === false) {
            return false;
        }
        $row = pg_fetch_assoc($result);
        return $row !== false && !is_null($row['oid']) && $row['oid'] !== '';
    }
}

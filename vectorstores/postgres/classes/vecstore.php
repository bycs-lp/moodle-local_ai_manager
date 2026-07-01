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

    #[\Override]
    public function is_available(): bool {
        $connection = $this->get_connection();
        if (!$connection) {
            return false;
        }
        return pg_query($connection, 'SELECT 1') !== false;
    }

    #[\Override]
    public function create_collection(string $collection, int $dimensions): bool {
        $connection = $this->get_connection();
        if (!$connection) {
            return false;
        }
        // The vector type requires the pgvector extension; create it if the user is allowed to.
        pg_query($connection, 'CREATE EXTENSION IF NOT EXISTS vector');
        $table = pg_escape_identifier($connection, $collection);
        $sql = "CREATE TABLE IF NOT EXISTS {$table} "
            . '(id text PRIMARY KEY, embedding vector(' . (int) $dimensions . '), payload jsonb)';
        return pg_query($connection, $sql) !== false;
    }

    #[\Override]
    public function delete_collection(string $collection): bool {
        $connection = $this->get_connection();
        if (!$connection) {
            return false;
        }
        $table = pg_escape_identifier($connection, $collection);
        return pg_query($connection, "DROP TABLE IF EXISTS {$table}") !== false;
    }

    #[\Override]
    public function upsert_embeddings(string $collection, array $embeddings): bool {
        $connection = $this->get_connection();
        if (!$connection) {
            return false;
        }
        $table = pg_escape_identifier($connection, $collection);
        $sql = "INSERT INTO {$table} (id, embedding, payload) VALUES ($1, $2::vector, $3::jsonb) "
            . 'ON CONFLICT (id) DO UPDATE SET embedding = EXCLUDED.embedding, payload = EXCLUDED.payload';

        $success = pg_query($connection, 'BEGIN') !== false;
        foreach ($embeddings as $embedding) {
            $params = [
                (string) $embedding['id'],
                $this->vector_to_literal($embedding['vector']),
                json_encode($embedding['payload'] ?? []),
            ];
            if (@pg_query_params($connection, $sql, $params) === false) {
                $success = false;
                break;
            }
        }
        pg_query($connection, $success ? 'COMMIT' : 'ROLLBACK');
        return $success;
    }

    #[\Override]
    public function query(string $collection, array $vector, int $topk = 5, array $filters = []): array {
        $connection = $this->get_connection();
        if (!$connection) {
            return [];
        }
        $table = pg_escape_identifier($connection, $collection);
        [$operator, $scoreexpr] = $this->distance_sql();

        $params = [$this->vector_to_literal($vector)];
        $where = '';
        if (!empty($filters)) {
            $params[] = json_encode($filters);
            $where = 'WHERE payload @> $2::jsonb';
        }

        $sql = "SELECT id, payload, {$scoreexpr} AS score FROM {$table} {$where} "
            . "ORDER BY embedding {$operator} \$1::vector ASC LIMIT " . (int) $topk;

        $result = @pg_query_params($connection, $sql, $params);
        if ($result === false) {
            return [];
        }
        $rows = pg_fetch_all($result);
        if (!is_array($rows)) {
            return [];
        }
        $matches = [];
        foreach ($rows as $row) {
            $matches[] = [
                'id' => $row['id'],
                'score' => is_null($row['score']) ? null : (float) $row['score'],
                'payload' => is_null($row['payload']) ? [] : json_decode($row['payload'], true),
            ];
        }
        return $matches;
    }

    #[\Override]
    public function delete_embeddings(string $collection, array $ids): bool {
        $connection = $this->get_connection();
        if (!$connection) {
            return false;
        }
        if (empty($ids)) {
            return true;
        }
        $table = pg_escape_identifier($connection, $collection);
        $elements = array_map(static fn($id): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $id) . '"', $ids);
        $arrayliteral = '{' . implode(',', $elements) . '}';
        $sql = "DELETE FROM {$table} WHERE id = ANY(\$1::text[])";
        return @pg_query_params($connection, $sql, [$arrayliteral]) !== false;
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
     * Lazily opens (and caches) the PostgreSQL connection from the instance's libpq connection string.
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
        $this->connection = $connection === false ? false : $connection;
        return $this->connection;
    }
}

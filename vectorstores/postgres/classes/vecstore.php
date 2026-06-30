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
 * @package    aivecstore_postgres
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class vecstore extends base_vecstore {
    #[\Override]
    public function is_available(): bool {
        // TODO Implement a check whether a pgvector enabled PostgreSQL connection is configured and reachable.
        return false;
    }

    #[\Override]
    public function create_collection(string $collection, int $dimensions): bool {
        // TODO Implement creation of a table with a "vector($dimensions)" column for the given collection.
        return false;
    }

    #[\Override]
    public function delete_collection(string $collection): bool {
        // TODO Implement dropping of the table that backs the given collection.
        return false;
    }

    #[\Override]
    public function upsert_embeddings(string $collection, array $embeddings): bool {
        // TODO Implement an INSERT ... ON CONFLICT DO UPDATE for the given embeddings.
        return false;
    }

    #[\Override]
    public function query(string $collection, array $vector, int $topk = 5, array $filters = []): array {
        // TODO Implement a nearest neighbour query using the pgvector distance operators (e.g. "<=>").
        return [];
    }

    #[\Override]
    public function delete_embeddings(string $collection, array $ids): bool {
        // TODO Implement a DELETE statement for the given identifiers.
        return false;
    }
}


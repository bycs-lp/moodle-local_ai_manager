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

namespace local_ai_manager\tests;

use local_ai_manager\base_vecstore;
use local_ai_manager\collection_not_found_exception;
use local_ai_manager\local\vecstore_query_response;
use local_ai_manager\local\vecstore_response;
use local_ai_manager\local\vector_record;

/**
 * In-memory vector store backend for testing the backend independent logic of {@see base_vecstore}.
 *
 * Similarity is not computed, a query returns the stored vectors matching the filters in insertion order.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class testable_vecstore extends base_vecstore {
    /** @var array[] The backend collections: name => ['dimensions' => int, 'vectors' => vector_record[]]. */
    public array $collections = [];

    /**
     * Uses the name of the Qdrant backend, because this test double is no real subplugin.
     *
     * @return string the localized name of the Qdrant backend
     */
    #[\Override]
    public function get_name(): string {
        return get_string('pluginname', 'aivecstore_qdrant');
    }

    #[\Override]
    public function is_available(): vecstore_response {
        return vecstore_response::create_from_result();
    }

    #[\Override]
    public function create_collection(string $collection, int $dimensions): vecstore_response {
        $this->collections[$collection] = ['dimensions' => $dimensions, 'vectors' => []];
        return vecstore_response::create_from_result();
    }

    #[\Override]
    public function delete_collection(string $collection): vecstore_response {
        unset($this->collections[$collection]);
        return vecstore_response::create_from_result();
    }

    #[\Override]
    protected function store_embeddings(string $collection, array $embeddings): vecstore_response {
        if (!isset($this->collections[$collection])) {
            throw new collection_not_found_exception();
        }
        array_push($this->collections[$collection]['vectors'], ...$embeddings);
        return vecstore_response::create_from_result();
    }

    #[\Override]
    public function query_collection(string $collection, array $vector, int $topk = 5, array $filters = []): vecstore_response {
        $matches = [];
        foreach ($this->collections[$collection]['vectors'] ?? [] as $embedding) {
            $documentids = (array) ($filters['documentid'] ?? [$embedding->get_documentid()]);
            if (in_array($embedding->get_documentid(), $documentids)) {
                $matches[] = $embedding;
            }
        }
        return vecstore_response::create_from_query_result(
            vecstore_query_response::create_from_result(array_slice($matches, 0, $topk))
        );
    }

    #[\Override]
    public function get_all(string $collection): vecstore_response {
        return $this->query_collection($collection, [], PHP_INT_MAX);
    }

    #[\Override]
    protected function delete_from_collection(string $collection, int ...$documentids): vecstore_response {
        if (isset($this->collections[$collection])) {
            $this->collections[$collection]['vectors'] = array_values(array_filter(
                $this->collections[$collection]['vectors'],
                static fn($embedding): bool => !in_array($embedding->get_documentid(), $documentids)
            ));
        }
        return vecstore_response::create_from_result();
    }

    /**
     * Makes the name building of collections accessible for tests.
     *
     * @param string $model the name of the embedding model
     * @return string the backend name of the collection
     */
    public function build_collection_name_for_test(string $model): string {
        return $this->build_collection_name($model);
    }
}

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

namespace local_ai_manager;

use core_plugin_manager;
use local_ai_manager\local\vecstore_query_response;
use local_ai_manager\local\vector_record;
use local_ai_manager\local\vecstore_response;

/**
 * Base class for vector store subplugins.
 *
 * A vector store subplugin (subplugintype "aivecstore") provides a backend implementation for storing and
 * querying vector embeddings, for example to implement retrieval augmented generation (RAG) use cases.
 * Each backend (Qdrant, PostgreSQL/pgvector, ...) implements the abstract methods defined here.
 *
 * Similarity is always measured as cosine similarity, so the scores of all backends share the same scale (-1 to 1) and can be
 * compared with each other (see {@see vector_record::get_score()}).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base_vecstore {
    /** @var int Maximum length of a collection name, PostgreSQL limits identifiers to 63 bytes. */
    const MAX_COLLECTION_NAME_LENGTH = 63;

    /** @var base_vecstore_instance the vector store instance providing the connection configuration */
    protected base_vecstore_instance $instance;

    /**
     * Create the vector store driver for a given configured instance.
     *
     * @param base_vecstore_instance $instance the vector store instance holding the connection configuration
     */
    public function __construct(base_vecstore_instance $instance) {
        $this->instance = $instance;
    }

    /**
     * Returns the vector store instance holding this driver's connection configuration.
     *
     * @return base_vecstore_instance the instance object
     */
    public function get_instance(): base_vecstore_instance {
        return $this->instance;
    }

    /**
     * Returns the localized name of the vector store backend.
     *
     * @return string the localized plugin name
     */
    public function get_name(): string {
        return get_string('pluginname', 'aivecstore_' . $this->get_plugin_name());
    }

    /**
     * Helper function for determining the plugin name based on this object.
     *
     * For example for the class \aivecstore_qdrant\vectorstore this will return 'qdrant'.
     *
     * @return string the plugin name without the 'aivecstore_' frankenstyle prefix
     */
    final public function get_plugin_name(): string {
        return preg_replace('/^aivecstore_(.*)\\\\.*/', '$1', get_class($this));
    }

    /**
     * Helper function to retrieve all enabled vector store subplugins.
     *
     * @return array array of strings of enabled vector store plugin names
     */
    final public static function get_all_vectorstores(): array {
        return core_plugin_manager::instance()->get_enabled_plugins('aivecstore');
    }

    /**
     * Returns the collections of this vector store instance.
     *
     * Every embedding model gets its own collection, because vectors of different embedding models must never be
     * compared with each other. A collection is registered when the first vectors of its embedding model are stored.
     *
     * @return string[] the backend collection names, indexed by the name of the embedding model
     */
    public function get_collections(): array {
        global $DB;
        return $DB->get_records_menu('local_ai_manager_collection', ['vecstoreid' => $this->instance->get_id()],
            'embeddingmodel', 'embeddingmodel, collection');
    }

    /**
     * Checks whether the vector store backend is correctly configured and reachable.
     *
     * @return vecstore_response structured response containing operation status and potential error details
     */
    abstract public function is_available(): vecstore_response;

    /**
     * Creates a collection (index) in the backend.
     *
     * @param string $collection the backend name of the collection
     * @param int $dimensions the dimensionality of the vectors the collection will hold
     * @return vecstore_response structured response containing operation status and potential error details
     */
    abstract public function create_collection(string $collection, int $dimensions): vecstore_response;

    /**
     * Deletes a collection (index) including all stored vectors from the backend.
     *
     * @param string $collection the backend name of the collection
     * @return vecstore_response structured response containing operation status and potential error details
     */
    abstract public function delete_collection(string $collection): vecstore_response;

    /**
     * Stores a set of embeddings created by the given embedding model.
     *
     * The embeddings are stored in the collection of the embedding model, which is created if it does not exist yet. Before
     * inserting, all existing vectors of this collection carrying one of the document ids of the given embeddings are deleted,
     * so that re-indexing a document replaces its previous vectors instead of accumulating duplicates.
     *
     * The embeddings are passed as {@see vector_record} objects. Each vecstore subplugin is responsible for building the
     * actual store call for its backend from the vector and the payload (see {@see self::store_embeddings()}). The backend
     * record identifier is generated internally by the subplugin, so callers neither provide nor need to know about it.
     *
     * @param string $model the name of the embedding model which created the embeddings
     * @param vector_record[] $embeddings array of vector records to store
     * @return vecstore_response structured response containing operation status and potential error details
     */
    public function insert_embeddings(string $model, array $embeddings): vecstore_response {
        if (empty($embeddings)) {
            return vecstore_response::create_from_result();
        }
        $dimensions = count(json_decode(reset($embeddings)->get_vector(), true, flags: JSON_THROW_ON_ERROR));

        $collectionrecord = $this->get_collection_record($model);
        if ($collectionrecord === null) {
            $collection = $this->build_collection_name($model);
            $response = $this->create_collection($collection, $dimensions);
            if ($response->get_code() !== 200) {
                return $response;
            }
            $collectionrecord = $this->register_collection($model, $collection, $dimensions);
        }
        $this->require_matching_dimensions($collectionrecord, $dimensions);

        // Replace semantics: first remove any existing vectors for the affected document ids.
        $documentids = [];
        foreach ($embeddings as $embedding) {
            $documentids[$embedding->get_documentid()] = $embedding->get_documentid();
        }
        $response = $this->delete_from_collection($collectionrecord->collection, ...array_values($documentids));
        if ($response->get_code() !== 200) {
            return $response;
        }
        return $this->with_existing_collection(
            $collectionrecord->collection,
            $dimensions,
            fn(): vecstore_response => $this->store_embeddings($collectionrecord->collection, $embeddings)
        );
    }

    /**
     * Performs the actual backend insert of the given embeddings into a collection.
     *
     * Called by {@see self::insert_embeddings()} after existing vectors of the affected documents have been removed.
     *
     * @param string $collection the backend name of the collection
     * @param vector_record[] $embeddings array of vector records to store
     * @return vecstore_response structured response containing operation status and potential error details
     * @throws collection_not_found_exception if the collection does not exist in the backend
     */
    abstract protected function store_embeddings(string $collection, array $embeddings): vecstore_response;

    /**
     * Performs a similarity search among the vectors created by the given embedding model.
     *
     * The query vector has to be created by the same embedding model. If no vectors of this embedding model have been
     * stored yet, the result is empty.
     *
     * @param string $model the name of the embedding model which created the query vector
     * @param array $vector the query embedding vector as array of floats
     * @param int $topk the maximum number of nearest neighbours to return
     * @param array $filters optional payload filters keyed by payload field. A scalar value matches that field
     *  exactly; an array value matches any of the given values (IN semantics), e.g.
     *  ['documentid' => [12, 34]] restricts the search to those document ids
     * @return vecstore_response structured response containing operation status and a query payload
     */
    public function query(string $model, array $vector, int $topk = 5, array $filters = []): vecstore_response {
        $collectionrecord = $this->get_collection_record($model);
        if ($collectionrecord === null) {
            return vecstore_response::create_from_query_result(vecstore_query_response::create_from_empty_result());
        }
        $this->require_matching_dimensions($collectionrecord, count($vector));
        return $this->query_collection($collectionrecord->collection, $vector, $topk, $filters);
    }

    /**
     * Performs a similarity search in a collection given by its backend name.
     *
     * Use {@see self::query()} for vectors stored by Moodle. This method is meant for collections managed outside of
     * Moodle, the caller is responsible for the query vector fitting the vectors of the collection. A missing collection
     * leads to an empty result. Backends fixing the metric per collection (Qdrant) require the collection to use the cosine
     * metric, otherwise the scores have another meaning.
     *
     * @param string $collection the backend name of the collection
     * @param array $vector the query embedding vector as array of floats
     * @param int $topk the maximum number of nearest neighbours to return
     * @param array $filters optional payload filters, see {@see self::query()}
     * @return vecstore_response structured response containing operation status and a query payload
     */
    abstract public function query_collection(string $collection, array $vector, int $topk = 5,
            array $filters = []): vecstore_response;

    /**
     * Retrieves all embeddings currently stored in a collection.
     *
     * @param string $collection the backend name of the collection
     * @return vecstore_response structured response containing operation status and a query payload
     */
    abstract public function get_all(string $collection): vecstore_response;

    /**
     * Deletes all embeddings carrying one of the given document ids.
     *
     * Deleting is idempotent: if there is nothing to delete (no matching embeddings or no collection), the operation
     * succeeds.
     *
     * @param ?string $model the name of the embedding model whose collection is affected, null for all collections
     * @param int ...$documentids the document ids whose embeddings should be deleted
     * @return vecstore_response structured response containing operation status and potential error details
     */
    public function delete_embeddings(?string $model, int ...$documentids): vecstore_response {
        $collections = $this->get_collections();
        if ($model !== null) {
            $collections = array_intersect_key($collections, [$model => true]);
        }
        foreach ($collections as $collection) {
            $response = $this->delete_from_collection($collection, ...$documentids);
            if ($response->get_code() !== 200) {
                return $response;
            }
        }
        return vecstore_response::create_from_result();
    }

    /**
     * Deletes the collection of an embedding model including all its vectors.
     *
     * @param string $model the name of the embedding model
     * @return vecstore_response structured response containing operation status and potential error details
     */
    public function drop_collection(string $model): vecstore_response {
        global $DB;
        $collectionrecord = $this->get_collection_record($model);
        if ($collectionrecord === null) {
            return vecstore_response::create_from_result();
        }
        $response = $this->delete_collection($collectionrecord->collection);
        if ($response->get_code() === 200) {
            $DB->delete_records('local_ai_manager_collection', ['id' => $collectionrecord->id]);
        }
        return $response;
    }

    /**
     * Deletes all embeddings in a collection that carry one of the given document ids.
     *
     * Has to succeed if there is nothing to delete (no matching embeddings or no collection).
     *
     * @param string $collection the backend name of the collection
     * @param int ...$documentids the document ids whose embeddings should be deleted
     * @return vecstore_response structured response containing operation status and potential error details
     */
    abstract protected function delete_from_collection(string $collection, int ...$documentids): vecstore_response;

    /**
     * Builds the backend name of the collection of an embedding model.
     *
     * The name consists of the collection prefix configured for the instance and the sanitized model name. Names exceeding
     * the length most backends support are shortened and made unique by a hash of the model name.
     *
     * @param string $model the name of the embedding model
     * @return string the backend name of the collection
     * @throws \moodle_exception if no collection prefix is configured, because a vector store cannot be used without one
     */
    protected function build_collection_name(string $model): string {
        $prefix = $this->instance->get_collection();
        if (empty($prefix)) {
            throw new \moodle_exception('exception_vecstorenocollection', 'local_ai_manager', '', $this->get_name());
        }
        $slug = trim(preg_replace('/[^a-z0-9]+/', '_', \core_text::strtolower($model)), '_');
        $name = $prefix . '_' . $slug;
        if (strlen($name) > self::MAX_COLLECTION_NAME_LENGTH) {
            $hash = substr(sha1($model), 0, 8);
            $name = substr($name, 0, self::MAX_COLLECTION_NAME_LENGTH - strlen($hash) - 1) . '_' . $hash;
        }
        return $name;
    }

    /**
     * Returns the registered collection of an embedding model.
     *
     * @param string $model the name of the embedding model
     * @return ?\stdClass the local_ai_manager_collection record or null if the model has no collection yet
     */
    protected function get_collection_record(string $model): ?\stdClass {
        global $DB;
        $record = $DB->get_record('local_ai_manager_collection',
            ['vecstoreid' => $this->instance->get_id(), 'embeddingmodel' => $model]);
        return $record ?: null;
    }

    /**
     * Registers the collection of an embedding model.
     *
     * @param string $model the name of the embedding model
     * @param string $collection the backend name of the collection
     * @param int $dimensions the dimensionality of the vectors of the embedding model
     * @return \stdClass the created local_ai_manager_collection record
     */
    protected function register_collection(string $model, string $collection, int $dimensions): \stdClass {
        global $DB;
        $now = \core\di::get(\core\clock::class)->time();
        $record = (object) [
            'vecstoreid' => $this->instance->get_id(),
            'embeddingmodel' => $model,
            'collection' => $collection,
            'dimensions' => $dimensions,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = $DB->insert_record('local_ai_manager_collection', $record);
        return $record;
    }

    /**
     * Ensures that vectors fit the dimensionality of the collection of their embedding model.
     *
     * A mismatch can only happen if the vectors of a model changed their dimensionality, for example because a model name
     * has been reused for another model.
     *
     * @param \stdClass $collectionrecord the local_ai_manager_collection record
     * @param int $dimensions the dimensionality of the vectors
     * @throws \moodle_exception if the dimensionalities do not match
     */
    protected function require_matching_dimensions(\stdClass $collectionrecord, int $dimensions): void {
        if ((int) $collectionrecord->dimensions === $dimensions) {
            return;
        }
        throw new \moodle_exception(
            'exception_vecstoredimensionmismatch',
            'local_ai_manager',
            '',
            [
                'model' => $collectionrecord->embeddingmodel,
                'modeldimensions' => $dimensions,
                'vecstoredimensions' => (int) $collectionrecord->dimensions,
            ]
        );
    }

    /**
     * Runs a store operation, transparently creating the collection if it does not exist in the backend (anymore).
     *
     * If the operation signals a missing collection by throwing a {@see collection_not_found_exception}, the
     * collection is created and the operation is retried once.
     *
     * @param string $collection the backend name of the collection
     * @param int $dimensions the dimensionality of the vectors of the collection
     * @param callable $operation the store operation to run
     * @return vecstore_response the operation result
     */
    protected function with_existing_collection(string $collection, int $dimensions, callable $operation): vecstore_response {
        try {
            return $operation();
        } catch (collection_not_found_exception $e) {
            $createcollectionresponse = $this->create_collection($collection, $dimensions);
            if ($createcollectionresponse->get_code() !== 200) {
                return $createcollectionresponse;
            }
            return $operation();
        }
    }
}

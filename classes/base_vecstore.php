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
use local_ai_content\local\enriched_vector;

/**
 * Base class for vector store subplugins.
 *
 * A vector store subplugin (subplugintype "aivecstore") provides a backend implementation for storing and
 * querying vector embeddings, for example to implement retrieval augmented generation (RAG) use cases.
 * Each backend (Qdrant, PostgreSQL/pgvector, ...) implements the abstract methods defined here.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base_vecstore {
    /** @var string Distance metric: cosine similarity. */
    const DISTANCE_COSINE = 'cosine';

    /** @var string Distance metric: dot product. */
    const DISTANCE_DOT = 'dot';

    /** @var string Distance metric: euclidean distance. */
    const DISTANCE_EUCLIDEAN = 'euclidean';

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
     * Returns the distance metric configured for this vector store instance.
     *
     * Subclasses may override this if their backend uses or requires a different metric.
     *
     * @return string one of the self::DISTANCE_* constants
     */
    public function get_distance_metric(): string {
        return $this->instance->get_distancemetric();
    }

    /**
     * Returns the name of the collection configured for this vector store instance.
     *
     * The collection is an internal detail of the vector store instance: it is created once (with the configured
     * name and dimensionality) and used for all subsequent operations. Callers therefore neither pass nor need to
     * know the collection name.
     *
     * @return string the configured collection name (empty string if none is configured)
     */
    public function get_collection(): string {
        return (string) $this->instance->get_collection();
    }

    /**
     * Checks whether the vector store backend is correctly configured and reachable.
     *
     * @return bool true if the backend is available, false otherwise
     */
    abstract public function is_available(): bool;

    /**
     * Creates the configured collection (index) for this instance using its configured dimensionality.
     *
     * @return bool true on success
     */
    abstract public function create_collection(): bool;

    /**
     * Deletes the configured collection (index) including all stored vectors.
     *
     * @return bool true on success
     */
    abstract public function delete_collection(): bool;

    /**
     * Stores a set of embeddings in the configured collection.
     *
     * Before inserting, all existing vectors carrying one of the context ids of the given embeddings are deleted, so
     * that re-indexing a context replaces its previous vectors instead of accumulating duplicates.
     *
     * The embeddings are passed as {@see enriched_vector} objects. Each vecstore subplugin is responsible for
     * extracting the required information via the getters and building the actual store call for its backend (see
     * {@see self::store_embeddings()}). The backend record identifier is generated internally by the subplugin, so
     * callers neither provide nor need to know about it.
     *
     * @param enriched_vector[] $embeddings array of enriched vector objects to store
     * @return bool true on success
     */
    public function insert_embeddings(array $embeddings): bool {
        // Replace semantics: first remove any existing vectors for the affected context ids.
        $contextids = [];
        foreach ($embeddings as $embedding) {
            $contextids[$embedding->get_contextid()] = $embedding->get_contextid();
        }
        foreach ($contextids as $contextid) {
            $this->delete_embeddings($contextid);
        }
        return $this->store_embeddings($embeddings);
    }

    /**
     * Performs the actual backend insert of the given embeddings into the configured collection.
     *
     * Called by {@see self::insert_embeddings()} after existing vectors of the affected contexts have been removed.
     *
     * @param enriched_vector[] $embeddings array of enriched vector objects to store
     * @return bool true on success
     */
    abstract protected function store_embeddings(array $embeddings): bool;

    /**
     * Performs a similarity search in the configured collection.
     *
     * @param array $vector the query embedding vector as array of floats
     * @param int $topk the maximum number of nearest neighbours to return
     * @param array $filters optional payload filters keyed by payload field. A scalar value matches that field
     *  exactly; an array value matches any of the given values (IN semantics), e.g.
     *  ['contextid' => [12, 34]] restricts the search to those context ids
     * @return enriched_vector[] array of enriched vector objects representing the matches
     */
    abstract public function query(array $vector, int $topk = 5, array $filters = []): array;

    /**
     * Retrieves all embeddings currently stored in the configured collection.
     *
     * @return enriched_vector[] array of all stored enriched vector objects (empty if the collection does not exist)
     */
    abstract public function get_all(): array;

    /**
     * Deletes all embeddings in the configured collection that carry the given context id as metadata.
     *
     * @param int $contextid the context id whose embeddings should be deleted
     * @return bool true on success
     */
    abstract public function delete_embeddings(int $contextid): bool;

    /**
     * Runs a store or retrieve operation, transparently creating the configured collection if it does not exist yet.
     *
     * If the operation signals a missing collection by throwing a {@see collection_not_found_exception}, the
     * collection is created with the configured name and dimensionality and the operation is retried once.
     *
     * @param callable $operation the store or retrieve operation to run
     * @return mixed the return value of the operation
     */
    protected function with_existing_collection(callable $operation): mixed {
        try {
            return $operation();
        } catch (collection_not_found_exception $e) {
            $this->create_collection();
            return $operation();
        }
    }
}

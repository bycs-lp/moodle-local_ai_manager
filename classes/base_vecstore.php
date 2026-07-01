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
     * Checks whether the vector store backend is correctly configured and reachable.
     *
     * @return bool true if the backend is available, false otherwise
     */
    abstract public function is_available(): bool;

    /**
     * Creates a collection (index) for storing vectors of the given dimensionality.
     *
     * @param string $collection the name of the collection to create
     * @param int $dimensions the dimensionality of the vectors that will be stored
     * @return bool true on success
     */
    abstract public function create_collection(string $collection, int $dimensions): bool;

    /**
     * Deletes a whole collection (index) including all stored vectors.
     *
     * @param string $collection the name of the collection to delete
     * @return bool true on success
     */
    abstract public function delete_collection(string $collection): bool;

    /**
     * Stores (inserts or updates) a set of embeddings in the given collection.
     *
     * Each embedding has the form:
     * [
     *     'id' => string|int,       // unique identifier of the vector
     *     'vector' => float[],      // the embedding vector
     *     'payload' => array,       // optional associative array of metadata
     * ]
     *
     * @param string $collection the name of the collection to store the embeddings in
     * @param array $embeddings array of embedding definitions as described above
     * @return bool true on success
     */
    abstract public function upsert_embeddings(string $collection, array $embeddings): bool;

    /**
     * Performs a similarity search in the given collection.
     *
     * @param string $collection the name of the collection to query
     * @param array $vector the query embedding vector as array of floats
     * @param int $topk the maximum number of nearest neighbours to return
     * @param array $filters optional associative array of payload filters to restrict the search
     * @return array array of matches, each containing at least 'id', 'score' and 'payload'
     */
    abstract public function query(string $collection, array $vector, int $topk = 5, array $filters = []): array;

    /**
     * Deletes specific embeddings from the given collection.
     *
     * @param string $collection the name of the collection to delete from
     * @param array $ids array of vector identifiers to delete
     * @return bool true on success
     */
    abstract public function delete_embeddings(string $collection, array $ids): bool;
}

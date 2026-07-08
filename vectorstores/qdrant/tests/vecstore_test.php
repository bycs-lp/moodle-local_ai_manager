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

use local_ai_manager\base_vecstore;
use local_ai_manager\base_vecstore_instance;
use local_ai_content\local\enriched_vector;

/**
 * Integration tests for the Qdrant vector store driver.
 *
 * These talk to a real Qdrant server and are skipped automatically when none is reachable, so they
 * are a no-op in CI. Locally, the 5.3 docker stack provides one at http://qdrant:6333; point
 * elsewhere with the QDRANT_TEST_ENDPOINT environment variable.
 *
 * @package    aivecstore_qdrant
 * @copyright  2026 Exputo Inc.
 * @author     David Pesce <david.pesce@exputo.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \aivecstore_qdrant\vecstore
 */
final class vecstore_test extends \advanced_testcase {
    /** @var vecstore the driver under test */
    private vecstore $driver;

    /** @var string the unique collection name used for this test run */
    private string $collection;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $endpoint = getenv('QDRANT_TEST_ENDPOINT') ?: 'http://qdrant:6333';
        $this->collection = 'moodle_test_' . uniqid();
        $instance = new base_vecstore_instance();
        $instance->set_vecstore('qdrant');
        $instance->set_endpoint($endpoint);
        $instance->set_apikey('');
        $instance->set_distancemetric(base_vecstore::DISTANCE_COSINE);
        // The collection is an internal detail of the instance; configure it here for all operations.
        $instance->set_collection($this->collection);
        $instance->set_dimensions(4);
        $this->driver = new vecstore($instance);

        if ($this->driver->is_available()->get_code() !== 200) {
            $this->markTestSkipped('No reachable Qdrant server at ' . $endpoint);
        }
    }

    #[\Override]
    protected function tearDown(): void {
        if (isset($this->driver) && isset($this->collection)) {
            // Best-effort cleanup; ignore the result.
            $this->driver->delete_collection();
        }
        parent::tearDown();
    }

    /**
     * Exercises the full lifecycle: create collection, insert, query, delete collection.
     */
    public function test_full_lifecycle(): void {
        $this->assertSame(200, $this->driver->create_collection()->get_code());

        $this->assertSame(200, $this->driver->insert_embeddings([
            enriched_vector::create(json_encode([1.0, 0.0, 0.0, 0.0]), 'x', 101, 0, 1),
            enriched_vector::create(json_encode([0.0, 1.0, 0.0, 0.0]), 'y', 102, 0, 1),
            enriched_vector::create(json_encode([0.0, 0.0, 1.0, 0.0]), 'z', 103, 0, 1),
        ])->get_code());

        // A query near the second vector should return it as the closest match.
        $queryresponse = $this->driver->query([0.0, 0.9, 0.1, 0.0], 2);
        $this->assertSame(200, $queryresponse->get_code());
        $matches = $queryresponse->get_queryresponse()->get_matches();
        $this->assertNotEmpty($matches);
        $this->assertInstanceOf(enriched_vector::class, $matches[0]);
        $this->assertSame('y', $matches[0]->get_content());
        $this->assertSame(102, $matches[0]->get_contextid());
        $this->assertNotSame('', $matches[0]->get_vector());

        // The get_all() call returns all stored vectors.
        $allresponse = $this->driver->get_all();
        $this->assertSame(200, $allresponse->get_code());
        $this->assertCount(3, $allresponse->get_queryresponse()->get_matches());

        $this->assertSame(200, $this->driver->delete_collection()->get_code());
    }

    /**
     * Filtered queries should only return points whose payload matches the filter.
     */
    public function test_query_with_filter(): void {
        $this->driver->create_collection();
        $this->driver->insert_embeddings([
            enriched_vector::create(json_encode([1.0, 0.0, 0.0, 0.0]), 'seven', 7, 0, 1),
            enriched_vector::create(json_encode([0.9, 0.1, 0.0, 0.0]), 'nine', 9, 0, 1),
        ]);

        $matches = $this->driver->query([1.0, 0.0, 0.0, 0.0], 5, ['contextid' => 9])->get_queryresponse()->get_matches();
        $contents = array_map(static fn($match) => $match->get_content(), $matches);
        $this->assertContains('nine', $contents);
        $this->assertNotContains('seven', $contents);
    }

    /**
     * A filter with an array of values matches any of them (IN semantics).
     */
    public function test_query_with_multivalue_filter(): void {
        $this->driver->create_collection();
        $this->driver->insert_embeddings([
            enriched_vector::create(json_encode([1.0, 0.0, 0.0, 0.0]), 'seven', 7, 0, 1),
            enriched_vector::create(json_encode([0.0, 1.0, 0.0, 0.0]), 'eight', 8, 0, 1),
            enriched_vector::create(json_encode([0.0, 0.0, 1.0, 0.0]), 'nine', 9, 0, 1),
        ]);

        // Restrict to context ids 7 and 9 (excluding 8) via an array value = IN semantics.
        $matches = $this->driver->query([1.0, 1.0, 1.0, 1.0], 10, ['contextid' => [7, 9]])->get_queryresponse()->get_matches();
        $contextids = array_map(static fn($match) => $match->get_contextid(), $matches);
        sort($contextids);
        $this->assertSame([7, 9], $contextids);
    }

    /**
     * Deleting by context id must remove all vectors carrying that context id and keep the rest.
     */
    public function test_delete_embeddings_by_contextid(): void {
        $this->driver->create_collection();
        $this->driver->insert_embeddings([
            enriched_vector::create(json_encode([1.0, 0.0, 0.0, 0.0]), 'keep', 101, 0, 2),
            enriched_vector::create(json_encode([0.0, 1.0, 0.0, 0.0]), 'gone-a', 102, 0, 2),
            enriched_vector::create(json_encode([0.0, 0.0, 1.0, 0.0]), 'gone-b', 102, 1, 2),
        ]);

        $this->assertSame(200, $this->driver->delete_embeddings(102)->get_code());

        $matches = $this->driver->query([1.0, 1.0, 1.0, 1.0], 10)->get_queryresponse()->get_matches();
        $contextids = array_map(static fn($match) => $match->get_contextid(), $matches);
        $this->assertContains(101, $contextids);
        $this->assertNotContains(102, $contextids);
    }

    /**
     * Inserting for a context id must first remove the existing vectors of that context (replace semantics).
     */
    public function test_insert_replaces_existing_context(): void {
        $this->driver->create_collection();
        $this->driver->insert_embeddings([
            enriched_vector::create(json_encode([1.0, 0.0, 0.0, 0.0]), 'old', 200, 0, 1),
        ]);
        // Re-inserting for the same context id must replace the previous vectors.
        $this->driver->insert_embeddings([
            enriched_vector::create(json_encode([0.0, 1.0, 0.0, 0.0]), 'new', 200, 0, 1),
        ]);

        $contents = array_map(
            static fn($vector) => $vector->get_content(),
            $this->driver->get_all()->get_queryresponse()->get_matches()
        );
        $this->assertContains('new', $contents);
        $this->assertNotContains('old', $contents);
        $this->assertCount(1, $contents);
    }

    /**
     * Inserting or querying a missing collection must transparently create it with the configured name and dimensions.
     */
    public function test_missing_collection_is_created_on_demand(): void {
        // The collection name and dimensions are configured on the instance but the collection is NOT created yet.
        // Querying a non-existent collection should create it and return an empty result set.
        $queryresponse = $this->driver->query([1.0, 0.0, 0.0, 0.0], 5);
        $this->assertSame(200, $queryresponse->get_code());
        $this->assertSame([], $queryresponse->get_queryresponse()->get_matches());

        // Inserting into the (now existing) collection works and the vector can be retrieved.
        $this->assertSame(200, $this->driver->insert_embeddings([
            enriched_vector::create(json_encode([1.0, 0.0, 0.0, 0.0]), 'hello', 55, 0, 1),
        ])->get_code());
        $matches = $this->driver->query([1.0, 0.0, 0.0, 0.0], 5)->get_queryresponse()->get_matches();
        $this->assertNotEmpty($matches);
        $this->assertSame('hello', $matches[0]->get_content());
    }
}

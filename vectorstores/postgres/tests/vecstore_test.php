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
use local_ai_manager\base_vecstore_instance;
use local_ai_content\local\enriched_vector;

/**
 * Integration tests for the PostgreSQL/pgvector vector store driver.
 *
 * These talk to a real pgvector-enabled PostgreSQL and are skipped automatically when none is
 * reachable, so they are a no-op in CI. Locally, the 5.3 docker stack provides one; override the
 * connection string with the PGVECTOR_TEST_DSN environment variable.
 *
 * @package    aivecstore_postgres
 * @copyright  2026 Exputo Inc.
 * @author     David Pesce <david.pesce@exputo.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \aivecstore_postgres\vecstore
 */
final class vecstore_test extends \advanced_testcase {
    /** @var vecstore the driver under test */
    private vecstore $driver;

    /** @var string the unique collection (table) name used for this test run */
    private string $collection;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $dsn = getenv('PGVECTOR_TEST_DSN') ?: 'postgresql://vectors:vectors@pgvector:5432/vectors';
        $this->collection = 'moodle_test_' . uniqid();
        $instance = new base_vecstore_instance();
        $instance->set_vecstore('postgres');
        $instance->set_apikey($dsn);
        $instance->set_distancemetric(base_vecstore::DISTANCE_COSINE);
        // The collection is an internal detail of the instance; configure it here for all operations.
        $instance->set_collection($this->collection);
        $instance->set_dimensions(4);
        $this->driver = new vecstore($instance);

        if (!$this->driver->is_available()) {
            $this->markTestSkipped('No reachable pgvector PostgreSQL for the configured DSN');
        }
    }

    #[\Override]
    protected function tearDown(): void {
        if (isset($this->driver) && isset($this->collection)) {
            $this->driver->delete_collection();
        }
        parent::tearDown();
    }

    /**
     * Exercises the full lifecycle: create table, insert, query, drop table.
     */
    public function test_full_lifecycle(): void {
        $this->assertTrue($this->driver->create_collection());

        $this->assertTrue($this->driver->insert_embeddings([
            enriched_vector::create(json_encode([1.0, 0.0, 0.0, 0.0]), 'x', 101, 0, 1),
            enriched_vector::create(json_encode([0.0, 1.0, 0.0, 0.0]), 'y', 102, 0, 1),
            enriched_vector::create(json_encode([0.0, 0.0, 1.0, 0.0]), 'z', 103, 0, 1),
        ]));

        // A query near the second vector should return it as the closest match.
        $matches = $this->driver->query([0.0, 0.9, 0.1, 0.0], 2);
        $this->assertNotEmpty($matches);
        $this->assertInstanceOf(enriched_vector::class, $matches[0]);
        $this->assertSame('y', $matches[0]->get_content());
        $this->assertSame(102, $matches[0]->get_contextid());
        $this->assertNotSame('', $matches[0]->get_vector());

        // The get_all() call returns all stored vectors.
        $this->assertCount(3, $this->driver->get_all());

        $this->assertTrue($this->driver->delete_collection());
    }

    /**
     * Filtered queries should only return rows whose payload contains the filter.
     */
    public function test_query_with_filter(): void {
        $this->driver->create_collection();
        $this->driver->insert_embeddings([
            enriched_vector::create(json_encode([1.0, 0.0, 0.0, 0.0]), 'seven', 7, 0, 1),
            enriched_vector::create(json_encode([0.9, 0.1, 0.0, 0.0]), 'nine', 9, 0, 1),
        ]);

        $matches = $this->driver->query([1.0, 0.0, 0.0, 0.0], 5, ['contextid' => 9]);
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
        $matches = $this->driver->query([1.0, 1.0, 1.0, 1.0], 10, ['contextid' => [7, 9]]);
        $contextids = array_map(static fn($match) => $match->get_contextid(), $matches);
        sort($contextids);
        $this->assertSame([7, 9], $contextids);
    }

    /**
     * Deleting by context id must remove all rows carrying that context id and keep the rest.
     */
    public function test_delete_embeddings_by_contextid(): void {
        $this->driver->create_collection();
        $this->driver->insert_embeddings([
            enriched_vector::create(json_encode([1.0, 0.0, 0.0, 0.0]), 'keep', 101, 0, 2),
            enriched_vector::create(json_encode([0.0, 1.0, 0.0, 0.0]), 'gone-a', 102, 0, 2),
            enriched_vector::create(json_encode([0.0, 0.0, 1.0, 0.0]), 'gone-b', 102, 1, 2),
        ]);

        $this->assertTrue($this->driver->delete_embeddings(102));

        $matches = $this->driver->query([1.0, 1.0, 1.0, 1.0], 10);
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

        $contents = array_map(static fn($vector) => $vector->get_content(), $this->driver->get_all());
        $this->assertContains('new', $contents);
        $this->assertNotContains('old', $contents);
        $this->assertCount(1, $contents);
    }

    /**
     * Inserting or querying a missing collection must transparently create it with the configured name and dimensions.
     */
    public function test_missing_collection_is_created_on_demand(): void {
        // The collection name and dimensions are configured on the instance but the table is NOT created yet.
        // Querying a non-existent collection should create it and return an empty result set.
        $this->assertSame([], $this->driver->query([1.0, 0.0, 0.0, 0.0], 5));

        // Inserting into the (now existing) collection works and the vector can be retrieved.
        $this->assertTrue($this->driver->insert_embeddings([
            enriched_vector::create(json_encode([1.0, 0.0, 0.0, 0.0]), 'hello', 55, 0, 1),
        ]));
        $matches = $this->driver->query([1.0, 0.0, 0.0, 0.0], 5);
        $this->assertNotEmpty($matches);
        $this->assertSame('hello', $matches[0]->get_content());
    }
}

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
use local_ai_manager\local\vector_record;

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

    /** @var string Name of the embedding model the test vectors belong to. */
    private const MODEL = 'test-model';

    /** @var string the unique collection (table) name prefix used for this test run */
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
        // Every embedding model gets its own collection named after this prefix.
        $instance->set_collection($this->collection);
        $instance->store();
        $this->driver = new vecstore($instance);

        if ($this->driver->is_available()->get_code() !== 200) {
            $this->markTestSkipped('No reachable pgvector PostgreSQL for the configured DSN');
        }
    }

    #[\Override]
    protected function tearDown(): void {
        if (isset($this->driver)) {
            foreach (array_keys($this->driver->get_collections()) as $model) {
                $this->driver->drop_collection($model);
            }
        }
        parent::tearDown();
    }

    /**
     * Exercises the full lifecycle: insert (creating the table of the model), query, drop table.
     */
    public function test_full_lifecycle(): void {
        $this->assertSame(200, $this->driver->insert_embeddings(self::MODEL, [
            new vector_record(json_encode([1.0, 0.0, 0.0, 0.0]), 'x', 101),
            new vector_record(json_encode([0.0, 1.0, 0.0, 0.0]), 'y', 102, ['chunk' => 2, 'locator' => 'p. 3']),
            new vector_record(json_encode([0.0, 0.0, 1.0, 0.0]), 'z', 103),
        ])->get_code());
        $collection = $this->driver->get_collections()[self::MODEL];
        $this->assertSame($this->collection . '_test_model', $collection);

        // A query near the second vector should return it as the closest match.
        $queryresponse = $this->driver->query(self::MODEL, [0.0, 0.9, 0.1, 0.0], 2);
        $this->assertSame(200, $queryresponse->get_code());
        $matches = $queryresponse->get_queryresponse()->get_matches();
        $this->assertNotEmpty($matches);
        $this->assertInstanceOf(vector_record::class, $matches[0]);
        $this->assertSame('y', $matches[0]->get_content());
        $this->assertSame(102, $matches[0]->get_documentid());
        $this->assertEquals(['chunk' => 2, 'locator' => 'p. 3'], $matches[0]->get_metadata());
        $this->assertNotSame('', $matches[0]->get_vector());

        // The get_all() call returns all stored vectors.
        $allresponse = $this->driver->get_all($collection);
        $this->assertSame(200, $allresponse->get_code());
        $this->assertCount(3, $allresponse->get_queryresponse()->get_matches());

        $this->assertSame(200, $this->driver->drop_collection(self::MODEL)->get_code());
        $this->assertSame([], $this->driver->get_collections());
    }

    /**
     * Filtered queries should only return rows whose payload contains the filter.
     */
    public function test_query_with_filter(): void {
        $this->driver->insert_embeddings(self::MODEL, [
            new vector_record(json_encode([1.0, 0.0, 0.0, 0.0]), 'seven', 7),
            new vector_record(json_encode([0.9, 0.1, 0.0, 0.0]), 'nine', 9),
        ]);

        $matches = $this->driver->query(self::MODEL, [1.0, 0.0, 0.0, 0.0], 5, ['documentid' => 9])
            ->get_queryresponse()->get_matches();
        $contents = array_map(static fn($match) => $match->get_content(), $matches);
        $this->assertContains('nine', $contents);
        $this->assertNotContains('seven', $contents);
    }

    /**
     * A filter with an array of values matches any of them (IN semantics).
     */
    public function test_query_with_multivalue_filter(): void {
        $this->driver->insert_embeddings(self::MODEL, [
            new vector_record(json_encode([1.0, 0.0, 0.0, 0.0]), 'seven', 7),
            new vector_record(json_encode([0.0, 1.0, 0.0, 0.0]), 'eight', 8),
            new vector_record(json_encode([0.0, 0.0, 1.0, 0.0]), 'nine', 9),
        ]);

        // Restrict to document ids 7 and 9 (excluding 8) via an array value = IN semantics.
        $matches = $this->driver->query(self::MODEL, [1.0, 1.0, 1.0, 1.0], 10, ['documentid' => [7, 9]])
            ->get_queryresponse()->get_matches();
        $documentids = array_map(static fn($match) => $match->get_documentid(), $matches);
        sort($documentids);
        $this->assertSame([7, 9], $documentids);
    }

    /**
     * Deleting by document id must remove all rows carrying that document id and keep the rest.
     */
    public function test_delete_embeddings_by_documentid(): void {
        $this->driver->insert_embeddings(self::MODEL, [
            new vector_record(json_encode([1.0, 0.0, 0.0, 0.0]), 'keep', 101),
            new vector_record(json_encode([0.0, 1.0, 0.0, 0.0]), 'gone-a', 102),
            new vector_record(json_encode([0.0, 0.0, 1.0, 0.0]), 'gone-b', 102),
        ]);

        $this->assertSame(200, $this->driver->delete_embeddings(null, 102)->get_code());

        $matches = $this->driver->query(self::MODEL, [1.0, 1.0, 1.0, 1.0], 10)->get_queryresponse()->get_matches();
        $documentids = array_map(static fn($match) => $match->get_documentid(), $matches);
        $this->assertContains(101, $documentids);
        $this->assertNotContains(102, $documentids);
    }

    /**
     * Inserting for a document id must first remove the existing vectors of that document (replace semantics).
     */
    public function test_insert_replaces_existing_context(): void {
        $this->driver->insert_embeddings(self::MODEL, [
            new vector_record(json_encode([1.0, 0.0, 0.0, 0.0]), 'old', 200),
        ]);
        // Re-inserting for the same document id must replace the previous vectors.
        $this->driver->insert_embeddings(self::MODEL, [
            new vector_record(json_encode([0.0, 1.0, 0.0, 0.0]), 'new', 200),
        ]);

        $contents = array_map(
            static fn($vector) => $vector->get_content(),
            $this->driver->get_all($this->driver->get_collections()[self::MODEL])->get_queryresponse()->get_matches()
        );
        $this->assertContains('new', $contents);
        $this->assertNotContains('old', $contents);
        $this->assertCount(1, $contents);
    }

    /**
     * Querying a model without collection returns an empty result, inserting creates the collection.
     */
    public function test_missing_collection_is_created_on_insert(): void {
        $queryresponse = $this->driver->query(self::MODEL, [1.0, 0.0, 0.0, 0.0], 5);
        $this->assertSame(200, $queryresponse->get_code());
        $this->assertSame([], $queryresponse->get_queryresponse()->get_matches());

        $this->assertSame(200, $this->driver->insert_embeddings(self::MODEL, [
            new vector_record(json_encode([1.0, 0.0, 0.0, 0.0]), 'hello', 55),
        ])->get_code());
        $matches = $this->driver->query(self::MODEL, [1.0, 0.0, 0.0, 0.0], 5)->get_queryresponse()->get_matches();
        $this->assertNotEmpty($matches);
        $this->assertSame('hello', $matches[0]->get_content());
    }

    /**
     * Querying a collection which does not exist in the backend returns an empty result.
     */
    public function test_query_collection_missing_returns_empty_result(): void {
        $queryresponse = $this->driver->query_collection($this->collection . '_external', [1.0, 0.0, 0.0, 0.0], 5);
        $this->assertSame(200, $queryresponse->get_code());
        $this->assertSame([], $queryresponse->get_queryresponse()->get_matches());
    }
}

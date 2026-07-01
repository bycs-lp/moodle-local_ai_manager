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
        $instance = new base_vecstore_instance();
        $instance->set_vecstore('postgres');
        $instance->set_apikey($dsn);
        $instance->set_distancemetric(base_vecstore::DISTANCE_COSINE);
        $this->driver = new vecstore($instance);

        if (!$this->driver->is_available()) {
            $this->markTestSkipped('No reachable pgvector PostgreSQL for the configured DSN');
        }
        $this->collection = 'moodle_test_' . uniqid();
    }

    #[\Override]
    protected function tearDown(): void {
        if (isset($this->driver) && isset($this->collection)) {
            $this->driver->delete_collection($this->collection);
        }
        parent::tearDown();
    }

    /**
     * Exercises the full lifecycle: create table, upsert, query, delete embeddings, drop table.
     */
    public function test_full_lifecycle(): void {
        $this->assertTrue($this->driver->create_collection($this->collection, 4));

        $this->assertTrue($this->driver->upsert_embeddings($this->collection, [
            ['id' => 1, 'vector' => [1.0, 0.0, 0.0, 0.0], 'payload' => ['label' => 'x']],
            ['id' => 2, 'vector' => [0.0, 1.0, 0.0, 0.0], 'payload' => ['label' => 'y']],
            ['id' => 3, 'vector' => [0.0, 0.0, 1.0, 0.0], 'payload' => ['label' => 'z']],
        ]));

        // A query near point 2 should return point 2 as the closest match (ids come back as text).
        $matches = $this->driver->query($this->collection, [0.0, 0.9, 0.1, 0.0], 2);
        $this->assertNotEmpty($matches);
        $this->assertSame('2', $matches[0]['id']);
        $this->assertArrayHasKey('score', $matches[0]);
        $this->assertSame('y', $matches[0]['payload']['label']);

        // After deleting point 2, it must no longer be returned.
        $this->assertTrue($this->driver->delete_embeddings($this->collection, [2]));
        $matches = $this->driver->query($this->collection, [0.0, 0.9, 0.1, 0.0], 3);
        $ids = array_column($matches, 'id');
        $this->assertNotContains('2', $ids);

        $this->assertTrue($this->driver->delete_collection($this->collection));
    }

    /**
     * Upserting an existing id must update it in place rather than create a duplicate.
     */
    public function test_upsert_updates_in_place(): void {
        $this->driver->create_collection($this->collection, 4);
        $this->driver->upsert_embeddings($this->collection, [
            ['id' => 1, 'vector' => [1.0, 0.0, 0.0, 0.0], 'payload' => ['v' => 1]],
        ]);
        $this->driver->upsert_embeddings($this->collection, [
            ['id' => 1, 'vector' => [0.0, 1.0, 0.0, 0.0], 'payload' => ['v' => 2]],
        ]);

        $matches = $this->driver->query($this->collection, [0.0, 1.0, 0.0, 0.0], 5);
        $this->assertCount(1, $matches);
        $this->assertSame('1', $matches[0]['id']);
        $this->assertSame(2, $matches[0]['payload']['v']);
    }

    /**
     * Filtered queries should only return rows whose payload contains the filter.
     */
    public function test_query_with_filter(): void {
        $this->driver->create_collection($this->collection, 4);
        $this->driver->upsert_embeddings($this->collection, [
            ['id' => 1, 'vector' => [1.0, 0.0, 0.0, 0.0], 'payload' => ['course' => 7]],
            ['id' => 2, 'vector' => [0.9, 0.1, 0.0, 0.0], 'payload' => ['course' => 9]],
        ]);

        $matches = $this->driver->query($this->collection, [1.0, 0.0, 0.0, 0.0], 5, ['course' => 9]);
        $ids = array_column($matches, 'id');
        $this->assertContains('2', $ids);
        $this->assertNotContains('1', $ids);
    }
}

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

use local_ai_manager\local\config_manager;
use local_ai_manager\local\tenant;
use local_ai_manager\local\vecstore_factory;
use stdClass;

/**
 * Test class for the base_vecstore_instance class and the vecstore_factory.
 *
 * @package    local_ai_manager
 * @copyright  2026 Exputo Inc.
 * @author     David Pesce <david.pesce@exputo.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_manager\base_vecstore_instance
 */
final class base_vecstore_instance_test extends \advanced_testcase {
    /**
     * Tests that a vector store instance can be stored and loaded again from the database.
     *
     * @covers \local_ai_manager\base_vecstore_instance::store
     * @covers \local_ai_manager\base_vecstore_instance::load
     */
    public function test_store_and_load(): void {
        $this->resetAfterTest();

        $instance = new base_vecstore_instance();
        $instance->set_name('My Qdrant');
        $instance->set_tenant('sometenant');
        $instance->set_vecstore('qdrant');
        $instance->set_endpoint('https://qdrant.example.com');
        $instance->set_apikey('secret');
        $instance->set_collection('moodle_rag');
        $instance->set_dimensions(1536);
        $instance->set_distancemetric(base_vecstore::DISTANCE_COSINE);
        $instance->store();

        $id = $instance->get_id();
        $this->assertNotEmpty($id);

        $loaded = new base_vecstore_instance($id);
        $this->assertSame('My Qdrant', $loaded->get_name());
        $this->assertSame('sometenant', $loaded->get_tenant());
        $this->assertSame('qdrant', $loaded->get_vecstore());
        $this->assertSame('https://qdrant.example.com', $loaded->get_endpoint());
        $this->assertSame('secret', $loaded->get_apikey());
        $this->assertSame('moodle_rag', $loaded->get_collection());
        $this->assertSame(1536, $loaded->get_dimensions());
        $this->assertSame(base_vecstore::DISTANCE_COSINE, $loaded->get_distancemetric());
    }

    /**
     * Tests that updating an existing instance does not create a second record.
     *
     * @covers \local_ai_manager\base_vecstore_instance::store
     */
    public function test_update_does_not_duplicate(): void {
        global $DB;
        $this->resetAfterTest();

        $instance = new base_vecstore_instance();
        $instance->set_name('Initial');
        $instance->set_vecstore('qdrant');
        $instance->store();
        $id = $instance->get_id();

        $instance->set_name('Renamed');
        $instance->store();

        $this->assertSame(1, $DB->count_records('local_ai_manager_vecstore'));
        $this->assertSame('Renamed', (new base_vecstore_instance($id))->get_name());
    }

    /**
     * Tests the store_formdata/get_formdata round trip via a backend subclass.
     *
     * @covers \local_ai_manager\base_vecstore_instance::store_formdata
     * @covers \local_ai_manager\base_vecstore_instance::get_formdata
     */
    public function test_formdata_roundtrip(): void {
        $this->resetAfterTest();

        $instance = new \aivecstore_qdrant\instance();
        $data = new stdClass();
        $data->name = '  Trimmed Name  ';
        $data->tenant = 'tenantx';
        $data->vecstore = 'qdrant';
        $data->endpoint = 'https://qdrant.example.com';
        $data->apikey = 'abc';
        $data->useglobalapikey = 0;
        $data->collection = 'col';
        $data->dimensions = '768';
        $data->distancemetric = base_vecstore::DISTANCE_DOT;
        $instance->store_formdata($data);

        $formdata = (new vecstore_factory())->get_vecstore_instance_by_id($instance->get_id())->get_formdata();
        $this->assertSame('Trimmed Name', $formdata->name);
        $this->assertSame('qdrant', $formdata->vecstore);
        $this->assertSame('col', $formdata->collection);
        $this->assertSame(768, $formdata->dimensions);
        $this->assertSame(base_vecstore::DISTANCE_DOT, $formdata->distancemetric);
    }

    /**
     * Tests that validation rejects an empty name, a non-SSL endpoint and non-positive dimensions.
     *
     * @covers \local_ai_manager\base_vecstore_instance::validation
     */
    public function test_validation(): void {
        $this->resetAfterTest();

        $instance = new base_vecstore_instance();
        $errors = $instance->validation(
            ['name' => '', 'endpoint' => 'http://insecure.example.com', 'dimensions' => '-5'],
            []
        );
        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayHasKey('endpoint', $errors);
        $this->assertArrayHasKey('dimensions', $errors);

        $instance = new base_vecstore_instance();
        $errors = $instance->validation(
            ['name' => 'Valid', 'endpoint' => 'https://secure.example.com', 'dimensions' => '1536'],
            []
        );
        $this->assertEmpty($errors);
    }

    /**
     * Tests that get_all_instances is scoped to the current tenant by default.
     *
     * @covers \local_ai_manager\base_vecstore_instance::get_all_instances
     */
    public function test_get_all_instances_tenant_scoped(): void {
        $this->resetAfterTest();

        foreach (['tenanta', 'tenanta', 'tenantb'] as $i => $tenantid) {
            $instance = new base_vecstore_instance();
            $instance->set_name('vs' . $i);
            $instance->set_vecstore('qdrant');
            $instance->set_tenant($tenantid);
            $instance->store();
        }

        \core\di::set(tenant::class, new tenant('tenanta'));
        $this->assertCount(2, base_vecstore_instance::get_all_instances());
        \core\di::set(tenant::class, new tenant('tenantb'));
        $this->assertCount(1, base_vecstore_instance::get_all_instances());
        $this->assertCount(3, base_vecstore_instance::get_all_instances(true));
    }

    /**
     * Tests that deleting an instance removes its database record.
     *
     * @covers \local_ai_manager\base_vecstore_instance::delete
     */
    public function test_delete(): void {
        global $DB;
        $this->resetAfterTest();

        $instance = new base_vecstore_instance();
        $instance->set_name('To delete');
        $instance->set_vecstore('qdrant');
        $instance->store();
        $id = $instance->get_id();

        $this->assertTrue($DB->record_exists('local_ai_manager_vecstore', ['id' => $id]));
        $instance->delete();
        $this->assertFalse($DB->record_exists('local_ai_manager_vecstore', ['id' => $id]));
    }

    /**
     * Tests that the factory resolves the correct per-backend instance class.
     *
     * @covers \local_ai_manager\local\vecstore_factory::get_new_instance
     * @covers \local_ai_manager\local\vecstore_factory::get_vecstore_instance_by_id
     */
    public function test_factory_resolves_backend_class(): void {
        $this->resetAfterTest();

        $factory = new vecstore_factory();
        $new = $factory->get_new_instance('qdrant');
        $this->assertInstanceOf(\aivecstore_qdrant\instance::class, $new);
        $this->assertSame('qdrant', $new->get_vecstore());

        $new->set_name('Factory test');
        $new->store();
        $loaded = $factory->get_vecstore_instance_by_id($new->get_id());
        $this->assertInstanceOf(\aivecstore_qdrant\instance::class, $loaded);
    }

    /**
     * Tests that the Postgres backend stores its connection string in the apikey column and surfaces it back.
     *
     * @covers \aivecstore_postgres\instance::extend_store_formdata
     * @covers \aivecstore_postgres\instance::get_extended_formdata
     */
    public function test_postgres_connection_string_maps_to_apikey(): void {
        $this->resetAfterTest();

        $dsn = 'postgresql://u:p@host:5432/db?sslmode=require';
        $instance = new \aivecstore_postgres\instance();
        $data = new stdClass();
        $data->name = 'PG';
        $data->tenant = 'tn';
        $data->vecstore = 'postgres';
        $data->connectionstring = $dsn;
        $data->collection = 'embeddings';
        $data->dimensions = '1536';
        $data->distancemetric = base_vecstore::DISTANCE_COSINE;
        $instance->store_formdata($data);

        $reloaded = (new vecstore_factory())->get_vecstore_instance_by_id($instance->get_id());
        $this->assertSame($dsn, $reloaded->get_apikey());
        $this->assertSame($dsn, $reloaded->get_formdata()->connectionstring);
    }

    /**
     * Tests that the Postgres backend requires a connection string.
     *
     * @covers \aivecstore_postgres\instance::extend_validation
     */
    public function test_postgres_validation_requires_connection_string(): void {
        $this->resetAfterTest();

        $instance = new \aivecstore_postgres\instance();
        $errors = $instance->validation(['name' => 'PG', 'connectionstring' => ''], []);
        $this->assertArrayHasKey('connectionstring', $errors);
    }

    /**
     * Tests primary vector store selection: none, auto-single, explicit override, and delete fallback.
     *
     * @covers \local_ai_manager\local\vecstore_factory::get_primary_instance
     * @covers \local_ai_manager\local\vecstore_factory::set_primary
     */
    public function test_primary_selection(): void {
        $this->resetAfterTest();

        $tenant = new tenant('tn');
        \core\di::set(tenant::class, $tenant);
        \core\di::set(config_manager::class, new config_manager($tenant));
        $factory = new vecstore_factory();

        // No instances configured yet.
        $this->assertNull($factory->get_primary_instance());

        // Exactly one instance → it is the primary automatically.
        $a = new base_vecstore_instance();
        $a->set_name('A');
        $a->set_vecstore('qdrant');
        $a->set_tenant('tn');
        $a->store();
        $this->assertSame($a->get_id(), $factory->get_primary_instance()->get_id());

        // Two instances and none set → must choose, so no primary.
        $b = new base_vecstore_instance();
        $b->set_name('B');
        $b->set_vecstore('qdrant');
        $b->set_tenant('tn');
        $b->store();
        $this->assertNull($factory->get_primary_instance());

        // Explicit selection wins.
        $factory->set_primary($b->get_id());
        $this->assertSame($b->get_id(), $factory->get_primary_instance()->get_id());

        // Deleting the primary clears the pointer; with one instance left, it auto-becomes primary.
        $b->delete();
        $this->assertSame($a->get_id(), $factory->get_primary_instance()->get_id());
    }
}

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

use local_ai_manager\local\tenant;
use local_ai_manager\local\tenant_factory;
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
        $data->distancemetric = base_vecstore::DISTANCE_DOT;
        $instance->store_formdata($data);

        $formdata = (new vecstore_factory())->get_vecstore_instance_by_id($instance->get_id())->get_formdata();
        $this->assertSame('Trimmed Name', $formdata->name);
        $this->assertSame('qdrant', $formdata->vecstore);
        $this->assertSame('col', $formdata->collection);
        $this->assertSame(base_vecstore::DISTANCE_DOT, $formdata->distancemetric);
    }

    /**
     * Tests that validation rejects an empty name and a non-SSL endpoint.
     *
     * @covers \local_ai_manager\base_vecstore_instance::validation
     */
    public function test_validation(): void {
        $this->resetAfterTest();

        $instance = new base_vecstore_instance();
        $errors = $instance->validation(
            ['name' => '', 'endpoint' => 'http://insecure.example.com'],
            []
        );
        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayHasKey('endpoint', $errors);

        $instance = new base_vecstore_instance();
        $errors = $instance->validation(
            ['name' => 'Valid', 'endpoint' => 'https://secure.example.com'],
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

        \core\di::get(tenant_factory::class)->set(new tenant('tenanta'));
        $this->assertCount(2, base_vecstore_instance::get_all_instances());
        \core\di::get(tenant_factory::class)->set(new tenant('tenantb'));
        $this->assertCount(1, base_vecstore_instance::get_all_instances());
        $this->assertCount(3, base_vecstore_instance::get_all_instances(true));
    }

    /**
     * Tests that deleting an instance removes its database record and the bookkeeping of its collections.
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
        $DB->insert_record('local_ai_manager_collection', ['vecstoreid' => $id, 'embeddingmodel' => 'model',
            'collection' => 'prefix_model', 'dimensions' => 3, 'timecreated' => 0, 'timemodified' => 0]);

        $this->assertTrue($DB->record_exists('local_ai_manager_vecstore', ['id' => $id]));
        $instance->delete();
        $this->assertFalse($DB->record_exists('local_ai_manager_vecstore', ['id' => $id]));
        $this->assertFalse($DB->record_exists('local_ai_manager_collection', ['vecstoreid' => $id]));
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
     * Creates and stores a qdrant vector store instance.
     *
     * @param string $name the name of the instance
     * @param string $tenant the identifier of the tenant the instance belongs to
     * @param bool $isglobal true if the instance should be available for all tenants
     * @return base_vecstore_instance the stored instance
     */
    private function create_instance(string $name, string $tenant, bool $isglobal = false): base_vecstore_instance {
        $instance = new base_vecstore_instance();
        $instance->set_name($name);
        $instance->set_vecstore('qdrant');
        $instance->set_tenant($tenant);
        $instance->set_global($isglobal);
        $instance->store();
        return $instance;
    }

    /**
     * Tests the resolving of the primary vector store of a tenant: none, auto-single, explicit selection and deletion.
     *
     * @covers \local_ai_manager\local\vecstore_factory::find_primary_instance
     * @covers \local_ai_manager\local\vecstore_factory::get_primary_instance
     * @covers \local_ai_manager\local\vecstore_factory::set_primary
     */
    public function test_primary_selection(): void {
        $this->resetAfterTest();

        \core\di::get(tenant_factory::class)->set(new tenant('tn'));
        $factory = new vecstore_factory();
        $configmanager = \core\di::get(\local_ai_manager\local\config_manager::class);

        // No instances configured yet.
        $this->assertNull($factory->find_primary_instance());

        // Exactly one instance: It becomes the primary one and is stored in the tenant config.
        $a = $this->create_instance('A', 'tn');
        $this->assertSame($a->get_id(), $factory->get_primary_instance()->get_id());
        $this->assertSame((string) $a->get_id(), $configmanager->get_config(vecstore_factory::CONFIG_PRIMARY));

        // Adding a second instance does not change the stored primary one.
        $b = $this->create_instance('B', 'tn');
        $this->assertSame($a->get_id(), $factory->get_primary_instance()->get_id());

        // Explicit selection wins.
        $factory->set_primary($b->get_id());
        $this->assertSame($b->get_id(), $factory->get_primary_instance()->get_id());

        // Deleting the primary one removes the config entry, the only remaining instance becomes the primary one.
        $b->delete();
        $this->assertSame($a->get_id(), $factory->get_primary_instance()->get_id());

        // Several instances without a valid config entry: There is no primary one.
        $this->create_instance('C', 'tn');
        $a->delete();
        $this->create_instance('D', 'tn');
        $this->assertNull($factory->find_primary_instance());
        $this->expectException(\moodle_exception::class);
        $factory->get_primary_instance();
    }

    /**
     * Tests that global vector stores are available for all tenants and can be their primary vector store.
     *
     * @covers \local_ai_manager\base_vecstore_instance::get_all_instances
     * @covers \local_ai_manager\base_vecstore_instance::delete
     * @covers \local_ai_manager\local\vecstore_factory::find_primary_instance
     */
    public function test_global_instances(): void {
        $this->resetAfterTest();

        $factory = new vecstore_factory();
        $tenantfactory = \core\di::get(tenant_factory::class);
        $global = $this->create_instance('Global', 'admintenant', true);
        $local = $this->create_instance('Local', 'tenanta');

        // Global and own instances count together, so tenanta has to choose its primary vector store.
        $tenantfactory->set(new tenant('tenanta'));
        $this->assertCount(2, base_vecstore_instance::get_all_instances());
        $this->assertNull($factory->find_primary_instance());
        $factory->set_primary($global->get_id());
        $this->assertSame($global->get_id(), $factory->find_primary_instance()->get_id());

        // The global instance is the only one available for tenantb, so it becomes its primary vector store.
        $tenantfactory->set(new tenant('tenantb'));
        $this->assertCount(1, base_vecstore_instance::get_all_instances());
        $this->assertSame($global->get_id(), $factory->find_primary_instance()->get_id());

        // An instance of another tenant is no valid config entry, so the only available instance is used instead.
        $factory->set_primary($local->get_id());
        $this->assertSame($global->get_id(), $factory->find_primary_instance()->get_id());

        // Deleting the global instance removes it as primary vector store of all tenants.
        $global->delete();
        $tenantfactory->set(new tenant('tenanta'));
        $this->assertSame($local->get_id(), $factory->find_primary_instance()->get_id());
    }
}

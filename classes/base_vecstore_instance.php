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
use local_ai_manager\plugininfo\aivecstore;
use stdClass;

/**
 * Instance class for a vector database connection.
 *
 * This is the per-tenant configuration of a vector store backend (the connection details a tenant
 * adds for a Qdrant, PostgreSQL/pgvector, etc. instance). It mirrors {@see base_instance} which serves
 * the same role for AI tool connectors. The actual backend operations live in {@see base_vecstore}.
 *
 * @package    local_ai_manager
 * @copyright  2026 Exputo Inc.
 * @author     David Pesce <david.pesce@exputo.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class base_vecstore_instance {
    /** @var ?stdClass The database record */
    protected ?stdClass $record = null;

    /** @var int The record id */
    protected int $id = 0;

    /** @var ?string The name of the instance */
    protected ?string $name = null;

    /** @var ?string The tenant the instance belongs to */
    protected ?string $tenant = null;

    /** @var bool If the instance is available for all tenants */
    protected bool $isglobal = false;

    /** @var ?string The vector store backend (aivecstore subplugin name) the instance belongs to */
    protected ?string $vecstore = null;

    /** @var ?string The endpoint of the instance */
    protected ?string $endpoint = null;

    /** @var ?string The API key of the instance */
    protected ?string $apikey = null;

    /** @var ?string If an eventually configured global API key should be used */
    protected ?string $useglobalapikey = null;

    /** @var ?string The prefix of the collection/index/table names the vectors live in */
    protected ?string $collection = null;

    /**
     * Create an object for this vector store instance and - if the instance already exists - load all data from database.
     *
     * @param int $id the (record) id of the instance, pass 0 if you want to create a new instance
     */
    public function __construct(int $id = 0) {
        $this->id = $id;
        $this->load();
    }

    /**
     * Loads the instance data from database, if exists, and stores it into the class variables.
     */
    final public function load(): void {
        global $DB;
        $record = $DB->get_record('local_ai_manager_vecstore', ['id' => $this->id]);
        if (!$record) {
            return;
        }
        $this->record = $record;
        $this->id = $record->id;
        $this->name = $record->name;
        $this->tenant = $record->tenant;
        $this->isglobal = !empty($record->isglobal);
        $this->vecstore = $record->vecstore;
        $this->endpoint = $record->endpoint;
        $this->apikey = $record->apikey;
        $this->useglobalapikey = $record->useglobalapikey;
        $this->collection = $record->collection;
    }

    /**
     * Persists the object data to the database.
     */
    final public function store(): void {
        global $DB;
        $clock = \core\di::get(\core\clock::class);
        $record = new stdClass();
        $record->name = $this->name;
        $record->tenant = $this->tenant;
        $record->isglobal = $this->isglobal ? 1 : 0;
        $record->vecstore = $this->vecstore;
        $record->endpoint = $this->endpoint;
        $record->apikey = $this->apikey;
        $record->useglobalapikey = $this->get_useglobalapikey() ? 1 : 0;
        $record->collection = $this->collection;
        $currenttime = $clock->time();
        $record->timemodified = $currenttime;
        if (is_null($this->record)) {
            $record->timecreated = $currenttime;
            $record->id = $DB->insert_record('local_ai_manager_vecstore', $record);
            $this->id = $record->id;
        } else {
            $record->id = $this->id;
            $DB->update_record('local_ai_manager_vecstore', $record);
        }
        $this->record = $record;
    }

    /**
     * Returns all vector store instance objects available for the current tenant.
     *
     * The vector store instances available for a tenant are its own instances and the global ones.
     *
     * @param bool $allinstances true if all instances should be returned, by default only the instances available for the current
     *  tenant are returned
     * @return array array of instance objects
     */
    public static function get_all_instances(bool $allinstances = false): array {
        global $DB;

        if ($allinstances) {
            $records = $DB->get_records('local_ai_manager_vecstore', null, 'id', 'id');
        } else {
            $records = $DB->get_records_select(
                'local_ai_manager_vecstore',
                'tenant = :tenant OR isglobal = 1',
                ['tenant' => \core\di::get(tenant_factory::class)->get()->get_identifier()],
                'id',
                'id'
            );
        }
        $instances = [];
        foreach ($records as $record) {
            $instances[] = new self($record->id);
        }
        return $instances;
    }

    /**
     * Returns if the instance is enabled.
     *
     * This is equivalent to whether the vector store subplugin this is an instance of is enabled.
     *
     * @return bool true if the instance is enabled, false otherwise
     */
    public function is_enabled(): bool {
        return in_array($this->vecstore, aivecstore::get_enabled_plugins());
    }

    /**
     * Standard getter.
     *
     * @return int the id of the instance
     */
    public function get_id(): int {
        return $this->id;
    }

    /**
     * Standard getter.
     *
     * @return ?string the name of the instance or null if not set
     */
    public function get_name(): ?string {
        return $this->name;
    }

    /**
     * Standard setter.
     *
     * @param string $name the name of the instance
     */
    public function set_name(string $name): void {
        $this->name = $name;
    }

    /**
     * Standard getter.
     *
     * @return ?string the tenant identifier or null if not set
     */
    public function get_tenant(): ?string {
        return $this->tenant;
    }

    /**
     * Standard setter.
     *
     * @param string $tenant the identifier of the tenant the instance belongs to
     */
    public function set_tenant(string $tenant): void {
        $this->tenant = $tenant;
    }

    /**
     * Standard getter.
     *
     * @return bool true if the instance is available for all tenants
     */
    public function is_global(): bool {
        return $this->isglobal;
    }

    /**
     * Standard setter.
     *
     * @param bool $isglobal true if the instance should be available for all tenants
     */
    public function set_global(bool $isglobal): void {
        $this->isglobal = $isglobal;
    }

    /**
     * Standard getter.
     *
     * @return ?string the vector store backend identifier or null if not set
     */
    public function get_vecstore(): ?string {
        return $this->vecstore;
    }

    /**
     * Standard setter.
     *
     * @param string $vecstore the vector store backend name
     */
    public function set_vecstore(string $vecstore): void {
        $this->vecstore = $vecstore;
    }

    /**
     * Standard getter.
     *
     * @return ?string the endpoint of this instance or null if not set
     */
    public function get_endpoint(): ?string {
        return $this->endpoint;
    }

    /**
     * Standard setter.
     *
     * @param ?string $endpoint the endpoint of this instance
     */
    public function set_endpoint(?string $endpoint): void {
        $this->endpoint = $endpoint;
    }

    /**
     * Standard getter.
     *
     * @return ?string the api key or null if not set
     */
    public function get_apikey(): ?string {
        return $this->apikey;
    }

    /**
     * Standard setter.
     *
     * @param ?string $apikey The API key of this instance
     */
    public function set_apikey(?string $apikey): void {
        $this->apikey = $apikey;
    }

    /**
     * Standard getter.
     *
     * @return bool if an eventually global api key should be used
     */
    public function get_useglobalapikey(): bool {
        return !empty($this->useglobalapikey);
    }

    /**
     * Standard setter.
     *
     * @param bool $useglobalapikey if the global API key should be used for this instance
     */
    public function set_useglobalapikey(bool $useglobalapikey): void {
        $this->useglobalapikey = $useglobalapikey ? '1' : '0';
    }

    /**
     * Standard getter.
     *
     * @return ?string the prefix of the collection/index names or null if not set
     */
    public function get_collection(): ?string {
        return $this->collection;
    }

    /**
     * Standard setter.
     *
     * @param ?string $collection the prefix of the collection/index names
     */
    public function set_collection(?string $collection): void {
        $this->collection = $collection;
    }

    /**
     * Returns if we have already a database record for this object.
     *
     * @return bool true if there is a database record
     */
    public function record_exists(): bool {
        if (!is_null($this->record)) {
            return true;
        } else {
            $this->load();
            return !is_null($this->record);
        }
    }

    /**
     * Passes the data of the object to a stdClass object which can be passed into a form to represent the initial values.
     *
     * @return stdClass the object containing the data for loading the form
     */
    final public function get_formdata(): stdClass {
        $this->load();
        $data = new stdClass();
        if (is_null($this->record)) {
            return $data;
        }
        $data->name = $this->get_name();
        $data->tenant = $this->get_tenant();
        $data->isglobal = $this->is_global();
        $data->vecstore = $this->get_vecstore();
        $data->endpoint = $this->get_endpoint();
        $data->apikey = $this->get_apikey();
        $data->useglobalapikey = $this->get_useglobalapikey();
        $data->collection = $this->get_collection();
        foreach ($this->get_extended_formdata() as $key => $value) {
            $data->{$key} = $value;
        }
        return $data;
    }

    /**
     * Function to extend the form definition for subclasses.
     *
     * @param \MoodleQuickForm $mform the mform object which can be modified by the subclass
     */
    protected function extend_form_definition(\MoodleQuickForm $mform): void {
    }

    /**
     * Function to extend the form data stdClass.
     *
     * Should be overwritten by subclasses to pass additional data to the configuration form when the form is loaded.
     *
     * @return stdClass the form data to pass to the form for loading
     */
    protected function get_extended_formdata(): stdClass {
        return new stdClass();
    }

    /**
     * Function to add form definitions to the edit form.
     *
     * @param \MoodleQuickForm $mform the mform object
     * @param array $customdata the customdata which has been passed to the form when created
     */
    final public function edit_form_definition(\MoodleQuickForm $mform, array $customdata): void {
        $textelementparams = ['style' => 'width: 100%'];
        $mform->addElement('static', 'vecstoreintro', '', get_string('vecstoreintro', 'local_ai_manager'));
        $mform->addElement(
            'text',
            'name',
            get_string('instancename', 'local_ai_manager'),
            array_merge($textelementparams, ['placeholder' => 'e.g. Production Qdrant'])
        );
        $mform->setType('name', PARAM_TEXT);

        $tenant = $this->record_exists() ? $this->get_tenant() : $customdata['tenant'];
        $mform->addElement('text', 'tenant', get_string('tenant', 'local_ai_manager'), $textelementparams);
        $mform->setType('tenant', PARAM_ALPHANUM);
        if (has_capability('local/ai_manager:managetenants', \context_system::instance())) {
            $mform->setDefault('tenant', $tenant);
        } else {
            // Constant instead of default, so a manipulated request cannot move the instance to another tenant.
            $mform->setConstant('tenant', $tenant);
            $mform->hardFreeze('tenant');
        }

        if (has_capability('local/ai_manager:manageglobalvecstores', \context_system::instance())) {
            $mform->addElement(
                'advcheckbox',
                'isglobal',
                get_string('vecstoreglobal', 'local_ai_manager'),
                get_string('vecstoreglobaldesc', 'local_ai_manager')
            );
            $mform->setType('isglobal', PARAM_BOOL);
        }

        $vecstore = $customdata['vecstore'];
        $vecstorecomponentname = 'aivecstore_' . $vecstore;
        $mform->addElement('hidden', 'vecstore', $vecstore);
        $mform->setType('vecstore', PARAM_TEXT);
        $mform->addElement(
            'static',
            'vecstoredisplayname',
            get_string('vecstore', 'local_ai_manager'),
            get_string('pluginname', $vecstorecomponentname)
        );

        $mform->addElement(
            'text',
            'endpoint',
            get_string('endpoint', 'local_ai_manager'),
            array_merge($textelementparams, ['placeholder' => 'https://your-qdrant-host:6333'])
        );
        $mform->setType('endpoint', PARAM_URL);
        $mform->addElement(
            'static',
            'endpointdescription',
            '',
            get_string('vecstoreendpointdesc', 'local_ai_manager')
        );

        if (get_config($vecstorecomponentname, 'globalapikey')) {
            // Only show the "use global apikey" checkbox if there is a global apikey configured.
            $mform->addElement(
                'advcheckbox',
                'useglobalapikey',
                get_string('globalapikey', 'local_ai_manager'),
                get_string('useglobalapikey', 'local_ai_manager')
            );
            $mform->setType('useglobalapikey', PARAM_BOOL);
            $mform->hideIf('apikey', 'useglobalapikey', 'checked');
        }

        $mform->addElement('passwordunmask', 'apikey', get_string('apikey', 'local_ai_manager'), $textelementparams);
        $mform->setType('apikey', PARAM_TEXT);

        $mform->addElement(
            'text',
            'collection',
            get_string('collection', 'local_ai_manager'),
            array_merge($textelementparams, ['placeholder' => 'e.g. moodle_rag'])
        );
        $mform->setType('collection', PARAM_TEXT);
        $mform->addHelpButton('collection', 'collection', 'local_ai_manager');

        $this->extend_form_definition($mform);
    }

    /**
     * Stores the form data after form has been submitted.
     *
     * @param stdClass $data the form data
     */
    final public function store_formdata(stdClass $data): void {
        $this->set_name(trim($data->name));
        $this->set_endpoint(!empty($data->endpoint) ? trim($data->endpoint) : null);
        $this->set_apikey(!empty($data->apikey) ? trim($data->apikey) : '');
        $this->set_useglobalapikey(!empty($data->useglobalapikey));
        $this->set_vecstore($data->vecstore);
        $tenantvalue = trim($data->tenant);
        $this->set_tenant(empty($tenantvalue) ? tenant::DEFAULT_IDENTIFIER : $tenantvalue);
        // The global flag is only part of the form for users allowed to manage global vector stores.
        if (property_exists($data, 'isglobal')) {
            $this->set_global(!empty($data->isglobal));
        }
        $this->set_collection(!empty($data->collection) ? trim($data->collection) : null);
        $this->extend_store_formdata($data);
        $this->store();
    }

    /**
     * Function to store additional form data.
     *
     * Should be overwritten by subclasses to store subclass specific form data.
     *
     * @param stdClass $data the form data after the form has been submitted
     */
    protected function extend_store_formdata(stdClass $data): void {
    }

    /**
     * Validates the form data after submission.
     *
     * @param array $data the form data
     * @param array $files the form data files
     * @return array associative array of the form ['nameofmformelement' => 'error if there is one'], should be empty if
     *  validation was successful
     */
    final public function validation(array $data, array $files): array {
        $errors = [];
        if (empty($data['name'])) {
            $errors['name'] = get_string('formvalidation_editinstance_name', 'local_ai_manager');
        }
        if (
            !empty($data['endpoint'])
            && str_starts_with($data['endpoint'], 'http://')
            && !str_starts_with($data['endpoint'], 'https://')
            && empty(get_config('local_ai_manager', 'allowinsecurevecstore'))
        ) {
            $errors['endpoint'] = get_string('formvalidation_editinstance_endpointnossl', 'local_ai_manager');
        }
        return $errors + $this->extend_validation($data, $files);
    }

    /**
     * Function to do some extra validation.
     *
     * Should be overwritten by subclasses to validate the subclass specific mform fields.
     *
     * @param array $data the form data
     * @param array $files the form data files
     * @return array associative array of the form ['nameofmformelement' => 'error if there is one'], should be empty if
     *   validation was successful
     */
    protected function extend_validation(array $data, array $files): array {
        return [];
    }

    /**
     * Deletes the record related to this object from database.
     *
     * @throws \moodle_exception if the record does not exist (anymore)
     */
    public function delete(): void {
        global $DB;
        if (empty($this->id)) {
            $this->load();
            if (empty($this->id)) {
                throw new \moodle_exception('exception_instancenotexists', 'local_ai_manager', '', $this->id);
            }
        }
        // Remove this instance as primary vector store of all tenants, global instances can be the primary one of many tenants.
        $DB->delete_records(
            'local_ai_manager_config',
            ['configkey' => \local_ai_manager\local\vecstore_factory::CONFIG_PRIMARY, 'configvalue' => $this->id]
        );
        // The collections stay in the backend, only our bookkeeping of them is removed.
        $DB->delete_records('local_ai_manager_collection', ['vecstoreid' => $this->id]);
        $DB->delete_records('local_ai_manager_vecstore', ['id' => $this->id]);
    }
}

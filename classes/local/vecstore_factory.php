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

namespace local_ai_manager\local;

use local_ai_manager\base_vecstore;
use local_ai_manager\base_vecstore_instance;
use local_ai_manager\hook\before_vecstore_instance_deleted;
use local_ai_manager\hook\vecstore_usage;

/**
 * Factory for creating/retrieving vector store instance objects.
 *
 * Mirrors {@see connector_factory} for the aivecstore subplugin type: it resolves the per-backend
 * instance class ('\aivecstore_<name>\instance') by naming convention.
 *
 * @package    local_ai_manager
 * @copyright  2026 Exputo Inc.
 * @author     David Pesce <david.pesce@exputo.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class vecstore_factory {
    /** @var string Config key (per tenant, in config_manager) storing the primary vector store instance id. */
    public const CONFIG_PRIMARY = 'primary_vecstore';

    /** @var string Plugin config key storing the id of the global vector store instance forced as primary of all tenants. */
    public const CONFIG_GLOBAL_PRIMARY = 'globalprimaryvecstore';

    /**
     * Returns the vector store instance object for a given instance id.
     *
     * @param int $id the vector store instance id (of the database record)
     * @return base_vecstore_instance the instance object
     */
    public function get_vecstore_instance_by_id(int $id): base_vecstore_instance {
        global $DB;
        $instancerecord = $DB->get_record('local_ai_manager_vecstore', ['id' => $id], '*', MUST_EXIST);
        $instanceclassname = '\\aivecstore_' . $instancerecord->vecstore . '\\instance';
        return new $instanceclassname($id);
    }

    /**
     * Returns a new vector store instance object for a given vector store backend.
     *
     * @param string $vecstorename the name of the vector store backend (aivecstore subplugin name)
     * @return base_vecstore_instance a new vector store instance object
     */
    public function get_new_instance(string $vecstorename): base_vecstore_instance {
        $instanceclassname = '\\aivecstore_' . $vecstorename . '\\instance';
        $instance = new $instanceclassname();
        $instance->set_vecstore($vecstorename);
        return $instance;
    }

    /**
     * Helper function to determine if a vector store instance already exists.
     *
     * @param int $id the id of the instance to check
     * @return bool true if the instance exists
     */
    public function instance_exists(int $id): bool {
        global $DB;
        return $DB->record_exists('local_ai_manager_vecstore', ['id' => $id]);
    }

    /**
     * Returns the vector store driver object (the backend implementation) for a given instance id.
     *
     * @param int $id the vector store instance id (of the database record)
     * @return base_vecstore the driver object configured with the instance
     */
    public function get_vecstore_by_id(int $id): base_vecstore {
        return $this->get_vecstore_by_instance($this->get_vecstore_instance_by_id($id));
    }

    /**
     * Returns the vector store driver object (the backend implementation) for a given instance.
     *
     * @param base_vecstore_instance $instance the configured vector store instance
     * @return base_vecstore the driver object configured with the instance
     */
    public function get_vecstore_by_instance(base_vecstore_instance $instance): base_vecstore {
        $vecstoreclassname = '\\aivecstore_' . $instance->get_vecstore() . '\\vecstore';
        return new $vecstoreclassname($instance);
    }

    /**
     * Returns the global primary vector store instance forced as primary vector store of all tenants or null if there is none.
     *
     * The global primary vector store is set by a site administrator, see {@see set_global_primary()}. It is only active as
     * long as its backend is enabled.
     *
     * @return ?base_vecstore_instance the global primary instance or null if there is no active global primary vector store
     */
    public function find_global_primary_instance(): ?base_vecstore_instance {
        // Setting a global primary vector store is optional.
        $id = (int) get_config('local_ai_manager', self::CONFIG_GLOBAL_PRIMARY);
        if ($id === 0) {
            return null;
        }
        $instance = $this->get_vecstore_instance_by_id($id);
        return $instance->is_enabled() ? $instance : null;
    }

    /**
     * Returns the primary vector store instance for the current tenant or null if it has none.
     *
     * An active global primary vector store (see {@see find_global_primary_instance()}) is the primary vector store of every
     * tenant, so Moodle contents are only indexed there. Otherwise the primary vector store is the one a tenant manager has
     * actively set in the tenant config, see {@see set_primary()}. It can be one of the own or of the global vector store
     * instances of the tenant.
     *
     * @return ?base_vecstore_instance the primary instance or null if the tenant has no primary vector store
     */
    public function find_primary_instance(): ?base_vecstore_instance {
        $globalprimary = $this->find_global_primary_instance();
        if ($globalprimary !== null) {
            return $globalprimary;
        }
        $primaryid = (int) \core\di::get(config_manager::class)->get_config(self::CONFIG_PRIMARY);
        foreach (base_vecstore_instance::get_all_instances() as $instance) {
            if ($instance->get_id() === $primaryid) {
                return $instance->is_enabled() ? $instance : null;
            }
        }
        return null;
    }

    /**
     * Returns the primary vector store instance for the current tenant.
     *
     * See {@see find_primary_instance()} for how the primary vector store is determined.
     *
     * @return base_vecstore_instance the primary instance
     * @throws \moodle_exception if the tenant has no primary vector store
     */
    public function get_primary_instance(): base_vecstore_instance {
        $instance = $this->find_primary_instance();
        if ($instance === null) {
            throw new \moodle_exception('exception_noprimaryvecstore', 'local_ai_manager');
        }
        return $instance;
    }

    /**
     * Returns the primary vector store driver (backend implementation) for the current tenant.
     *
     * @return base_vecstore the primary driver
     * @throws \moodle_exception if the tenant has no primary vector store
     */
    public function get_primary_vecstore(): base_vecstore {
        return $this->get_vecstore_by_instance($this->get_primary_instance());
    }

    /**
     * Marks the given instance as the primary vector store for the current tenant.
     *
     * Sources already indexed in the previous primary vector store stay there, only new indexings use the new one.
     *
     * @param int $id the id of the vector store instance available for the current tenant to make primary
     * @throws \moodle_exception if a global primary vector store is forced for all tenants
     */
    public function set_primary(int $id): void {
        if ($this->find_global_primary_instance() !== null) {
            throw new \moodle_exception('exception_globalprimaryvecstoreforced', 'local_ai_manager');
        }
        \core\di::get(config_manager::class)->set_config(self::CONFIG_PRIMARY, (string) $id);
    }

    /**
     * Forces the given global instance as primary vector store of all tenants.
     *
     * The primary vector stores of the tenants are degraded to normal ones, see {@see reset_tenant_primaries()}.
     *
     * @param int $id the id of the global vector store instance to force as primary of all tenants
     * @throws \coding_exception if the instance is not a global one
     */
    public function set_global_primary(int $id): void {
        if (!$this->get_vecstore_instance_by_id($id)->is_global()) {
            throw new \coding_exception('Only global vector store instances can be the primary vector store of all tenants');
        }
        set_config(self::CONFIG_GLOBAL_PRIMARY, $id, 'local_ai_manager');
        $this->reset_tenant_primaries();
    }

    /**
     * Degrades the primary vector stores of all tenants to normal ones.
     *
     * Called whenever a global primary vector store becomes active, so a tenant has no primary vector store anymore once the
     * global primary vector store is not active anymore and a tenant manager has to actively set one again.
     */
    public function reset_tenant_primaries(): void {
        global $DB;
        $DB->delete_records('local_ai_manager_config', ['configkey' => self::CONFIG_PRIMARY]);
        // Also reloads the config of the current tenant cached in the config manager.
        \core\di::get(config_manager::class)->unset_config(self::CONFIG_PRIMARY);
    }

    /**
     * Collect the data of other plugins depending on a vector store instance.
     *
     * @param base_vecstore_instance $instance The vector store instance.
     * @return string[][] Localized usage descriptions, indexed by component.
     */
    public function get_usage_descriptions(base_vecstore_instance $instance): array {
        $hook = new vecstore_usage($instance);
        \core\di::get(\core\hook\manager::class)->dispatch($hook);
        return $hook->get_usage_descriptions();
    }

    /**
     * Delete a vector store instance after letting other plugins detach their data from it.
     *
     * @param base_vecstore_instance $instance The vector store instance to delete.
     * @param bool $deletevectors Whether the stored vectors of the depending data are deleted from the instance.
     */
    public function delete_instance(base_vecstore_instance $instance, bool $deletevectors): void {
        \core\di::get(\core\hook\manager::class)->dispatch(new before_vecstore_instance_deleted($instance, $deletevectors));
        $instance->delete();
    }
}

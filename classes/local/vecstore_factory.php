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
use local_ai_manager\hook\before_primary_vecstore_changed;
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
     * Returns the primary vector store instance for the current tenant.
     *
     * The primary is the instance explicitly set via {@see set_primary()}. As a convenience, if none
     * is set (or the set one no longer exists) but the tenant has exactly one configured instance,
     * that sole instance is treated as the primary.
     *
     * @return ?base_vecstore_instance the primary instance, or null if the tenant has none / must choose
     */
    public function get_primary_instance(): ?base_vecstore_instance {
        $configmanager = \core\di::get(config_manager::class);
        $primaryid = (int) $configmanager->get_config(self::CONFIG_PRIMARY);
        if ($primaryid && $this->instance_exists($primaryid)) {
            $instance = $this->get_vecstore_instance_by_id($primaryid);
            if ($instance->get_tenant() === $configmanager->get_tenant()->get_identifier()) {
                return $instance;
            }
        }
        // Auto: if the tenant has exactly one configured instance, treat it as the primary.
        $all = base_vecstore_instance::get_all_instances();
        if (count($all) === 1) {
            return reset($all);
        }
        return null;
    }

    /**
     * Returns the primary vector store driver (backend implementation) for the current tenant.
     *
     * @return ?base_vecstore the primary driver, or null if the tenant has no primary vector store
     */
    public function get_primary_vecstore(): ?base_vecstore {
        $instance = $this->get_primary_instance();
        return $instance ? $this->get_vecstore_by_instance($instance) : null;
    }

    /**
     * Marks the given instance as the primary vector store for the current tenant.
     *
     * @param int $id the vector store instance id to make primary
     */
    public function set_primary(int $id): void {
        \core\di::get(config_manager::class)->set_config(self::CONFIG_PRIMARY, (string) $id);
    }

    /**
     * Collect the data of other plugins depending on a vector store instance.
     *
     * @param base_vecstore_instance $instance The vector store instance.
     * @param bool $deleting True if the instance is about to be deleted, false if it stops being the primary one.
     * @return string[][] Localized usage descriptions, indexed by component.
     */
    public function get_usage_descriptions(base_vecstore_instance $instance, bool $deleting): array {
        $hook = new vecstore_usage($instance, $deleting);
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

    /**
     * Make another instance the primary vector store after letting other plugins detach their data from the old one.
     *
     * @param base_vecstore_instance $newprimary The vector store instance becoming the primary one.
     * @param bool $deletevectors Whether the stored vectors of the depending data are deleted from the old primary.
     */
    public function change_primary(base_vecstore_instance $newprimary, bool $deletevectors): void {
        $oldprimary = $this->get_primary_instance();
        if ($oldprimary !== null && $oldprimary->get_id() !== $newprimary->get_id()) {
            \core\di::get(\core\hook\manager::class)->dispatch(
                new before_primary_vecstore_changed($oldprimary, $newprimary, $deletevectors)
            );
        }
        $this->set_primary($newprimary->get_id());
    }
}

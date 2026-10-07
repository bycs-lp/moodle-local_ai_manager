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

use local_ai_manager\base_instance;
use local_ai_manager\hook\custom_tenant;

/**
 * Class for managing the configuration of tenants.
 *
 * @package    local_ai_manager
 * @copyright  2024 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class access_manager {
    /**
     * Creates the access_manager object.
     *
     * The access manager always works with the tenant of the passed {@see tenant_factory} object. For the access manager
     * retrieved from the DI container, this is the current tenant of the request.
     *
     * @param tenant_factory $tenantfactory the object providing the tenant the access manager should work with
     */
    public function __construct(
        /** @var tenant_factory $tenantfactory the object providing the tenant the access manager should work with */
        private readonly tenant_factory $tenantfactory
    ) {
    }

    /**
     * Requires the current user to be a member or a manager of the tenant with the given identifier.
     *
     * The check is being performed without changing the tenant of this access manager or the current tenant of the request,
     * so it can be called before switching to the requested tenant.
     *
     * Determining the tenant (context) fails for invalid or non-existing tenant identifiers. To not reveal which tenants exist,
     * such failures result in the same exception as a missing permission. Database errors (except for missing records) and
     * other unexpected errors are not being caught, so they are not disguised as a missing permission.
     *
     * @param string $identifier the identifier of the tenant the current user wants to access
     * @throws \moodle_exception if the current user must not access the tenant
     * @throws \dml_exception in case of a database error
     */
    public function require_tenant_access(string $identifier): void {
        try {
            $tenantfactory = new tenant_factory();
            $tenantfactory->set(new tenant($identifier));
            $accessmanager = new self($tenantfactory);
            $allowed = $accessmanager->is_tenant_member() || $accessmanager->is_tenant_manager();
        } catch (\moodle_exception $exception) {
            // The tenant (context) could not be determined, for example because of an invalid identifier
            // (invalid_parameter_exception) or because a plugin implementing the custom_tenant hook does not know the tenant.
            // Real database errors must not be disguised as a missing permission. A missing record however just means that
            // the tenant (context) does not exist.
            if ($exception instanceof \dml_exception && !$exception instanceof \dml_missing_record_exception) {
                throw $exception;
            }
            $allowed = false;
        }
        if (!$allowed) {
            throw new \moodle_exception('exception_tenantaccessdenied', 'local_ai_manager', '', $identifier);
        }
    }

    /**
     * Requires the current user to be a manager of the current tenant.
     *
     * @throws \moodle_exception in case of the current user does not have sufficient permissions for managing the current tenant
     */
    public function require_tenant_manager(): void {
        if (!$this->is_tenant_manager()) {
            // phpcs:disable moodle.Commenting.TodoComment.MissingInfoInline
            // TODO Make a clean require_capability_exception out of this.
            // phpcs:enable moodle.Commenting.TodoComment.MissingInfoInline
            throw new \moodle_exception('exception_notenantmanagerrights', 'local_ai_manager');
        }
    }

    /**
     * Determines if a user is a tenant manager.
     *
     * @param int $userid the user id of the user to check, or empty/0 if current user should be used
     * @param ?tenant $tenant the tenant to use, if not passed or null the currently used tenant is being used
     * @return bool true if the current user is a tenant manager
     */
    public function is_tenant_manager(int $userid = 0, ?tenant $tenant = null): bool {
        global $USER;
        if (empty($userid)) {
            $userid = $USER->id;
        }
        if (has_capability('local/ai_manager:managetenants', \context_system::instance(), $userid)) {
            return true;
        }

        if (is_null($tenant)) {
            $tenant = $this->tenantfactory->get();
        }

        $customtenant = new custom_tenant($tenant);
        \core\di::get(\core\hook\manager::class)->dispatch($customtenant);

        // In case of default tenant we get system context here, admin should have all capabilities, so we need no admin check.
        $tenantcontext = $tenant->get_context();

        $user = empty($userid) ? $USER : \core_user::get_user($userid);
        if ($tenantcontext === \context_system::instance()) {
            // If the context of the tenant is systemwide, we distinguish between the capabilities "manage" and "managetenants":
            // If someone has the manage capability on system context, he/she will also have to be member of the tenant to be able
            // to manage it.
            $tenantfield = get_config('local_ai_manager', 'tenantcolumn');
            return has_capability('local/ai_manager:manage', $tenantcontext, $user) && $tenant->is_tenant_allowed()
                && $user->{$tenantfield} === $tenant->get_sql_identifier();
        }
        return has_capability('local/ai_manager:manage', $tenantcontext, $user) && $tenant->is_tenant_allowed();
    }

    /**
     * Utility function to check if the current user belongs to the currently active tenant.
     *
     * This function will not check any capabilities, only the membership.
     *
     * @return bool true if the user belongs to the tenant, false otherwise
     */
    public function is_tenant_member(): bool {
        global $USER;
        $tenantfield = get_config('local_ai_manager', 'tenantcolumn');

        return $USER->{$tenantfield} === $this->tenantfactory->get()->get_sql_identifier();
    }

    /**
     * Requires the current user to be a member of the currently set tenant.
     *
     * @throws \moodle_exception if the tenant is not allowed or the user must not use this tenant
     */
    public function require_tenant_member(): void {
        global $USER;
        $tenant = $this->tenantfactory->get();
        if (!$tenant->is_tenant_allowed()) {
            throw new \moodle_exception('exception_tenantnotallowed', 'local_ai_manager');
        }
        if ($tenant->is_default_tenant() && has_capability('local/ai_manager:use', $tenant->get_context())) {
            return;
        }

        $customtenant = new custom_tenant($tenant);
        \core\di::get(\core\hook\manager::class)->dispatch($customtenant);

        $tenantfield = get_config('local_ai_manager', 'tenantcolumn');
        if (empty($USER->{$tenantfield}) || $USER->{$tenantfield} !== $tenant->get_sql_identifier()) {
            throw new \moodle_exception('exception_tenantaccessdenied', 'local_ai_manager', '', $tenant->get_identifier());
        }
    }

    /**
     * Helper function to determine if the current user has the capability to manage a connector instance.
     *
     * @param base_instance $instance The connector instance the capability should be checked for
     * @return bool true if the current user is allowed to manage the instance
     */
    public function can_manage_connectorinstance(base_instance $instance) {
        global $USER;
        if (has_capability('local/ai_manager:managetenants', \context_system::instance())) {
            return true;
        }
        if ($this->is_tenant_manager($USER->id, new tenant($instance->get_tenant()))) {
            return has_capability('local/ai_manager:manage', $this->tenantfactory->get()->get_context());
        }
        return false;
    }
}

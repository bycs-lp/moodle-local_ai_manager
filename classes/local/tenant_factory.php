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

/**
 * Holder for the tenant the current request (or the current web service call) is working with.
 *
 * This object is a singleton in the DI container. Entry points (pages, web services) set the tenant they want to work with,
 * all tenant dependent objects retrieved from the DI container ({@see config_manager}, {@see access_manager},
 * {@see connector_factory}) ask this object for the tenant whenever they need it. So setting another tenant does not require
 * to rebuild or rebind any other object in the DI container.
 *
 * If no tenant has been set explicitly, the tenant of the current user is being used.
 *
 * Web service batch requests run several external functions in the same PHP process. To prevent a tenant set by one external
 * function from leaking into the next one, the tenant is being reset before each external function, see
 * {@see local_ai_manager_override_webservice_execution()}.
 *
 * Outside of web services the tenant is NOT being reset automatically: A tenant once set stays active for the rest of the PHP
 * process. This especially affects CLI scripts and cron runs, in which several scheduled and adhoc tasks are being executed one
 * after another in the same PHP process without the DI container being reset. Code running in such places should not set the
 * tenant at all (the tenant of the current user, for example set via {@see \core\cron::setup_user()}, is being determined
 * dynamically) or has to call {@see tenant_factory::reset()} afterwards (for example in a finally block).
 *
 * If tenant dependent objects are needed for a specific tenant without changing the current tenant of the request, a separate
 * tenant factory instance with the tenant being set can be passed to them.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tenant_factory {
    /** @var ?tenant $tenant the explicitly set tenant, null if the tenant of the current user should be used */
    private ?tenant $tenant = null;

    /**
     * Returns the tenant which is currently being used.
     *
     * @return tenant the explicitly set tenant or the tenant of the current user if no tenant has been set
     * @throws \invalid_parameter_exception if no tenant has been set and the tenant field of the current user is invalid
     */
    public function get(): tenant {
        // The tenant of the user is determined on every call, so a change of the current user is respected.
        return $this->tenant ?? new tenant();
    }

    /**
     * Sets the tenant to use for the rest of the current request or the current web service call.
     *
     * The caller is responsible for checking if the current user is allowed to access the tenant.
     *
     * @param tenant $tenant the tenant to use
     */
    public function set(tenant $tenant): void {
        $this->tenant = $tenant;
    }

    /**
     * Resets the tenant, so the tenant of the current user is being used again.
     */
    public function reset(): void {
        $this->tenant = null;
    }
}

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

use local_ai_manager\hook\custom_tenant;

/**
 * Data object class for handling usage information when using an AI tool.
 *
 * @package    local_ai_manager
 * @copyright  2024 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tenant {
    /** @var string identifier of the default tenant */
    public const DEFAULT_IDENTIFIER = 'default';

    /**
     * @var string identifier of the template tenant
     *
     * The template tenant is a reserved tenant no user belongs to. Its AI tools and purpose assignments are offered to all other
     * tenants as a starting configuration. It is not being passed to plugins implementing the {@see custom_tenant} hook.
     * The identifier is prefixed with the component name to avoid collisions with the identifiers of real tenants.
     */
    public const TEMPLATE_IDENTIFIER = 'local_ai_manager_template';

    /** @var string The identifier of the current tenant */
    private string $identifier;

    /**
     * Tenant class constructor.
     *
     * @param string $identifier the tenant identifier; if left empty, the default tenant is being used
     * @throws \invalid_parameter_exception If the provided identifier is invalid
     */
    public function __construct(string $identifier = '') {
        global $USER;
        if (empty($identifier)) {
            $tenantfield = get_config('local_ai_manager', 'tenantcolumn');
            $identifier = !empty($USER->{$tenantfield}) ? $USER->{$tenantfield} : '';
            if ($identifier === self::TEMPLATE_IDENTIFIER) {
                throw new \invalid_parameter_exception('Users must not belong to the template tenant.');
            }
            if (empty($identifier)) {
                $identifier = self::DEFAULT_IDENTIFIER;
            }
        }

        if (!self::is_valid_identifier($identifier)) {
            throw new \invalid_parameter_exception(
                'Tenant identifiers may not have leading or trailing whitespace and can only contain alphanumeric ' .
                'Latin characters, hyphens, underscores or blank spaces.'
            );
        }

        $this->identifier = $identifier;
    }

    /**
     * Check whether the given identifier is valid.
     * Valid identifiers consist of alphanumeric Latin characters including common diacritics (umlauts etc.),
     * blank spaces that are not trailing or leading, hyphens and underscores.
     *
     * @param string $identifier tenant identifier
     * @return bool true if valid, false otherwise
     */
    public static function is_valid_identifier(string $identifier): bool {
        if ($identifier !== trim($identifier)) {
            return false;
        }

        return preg_match('/^[\p{Latin}\p{N}_\- ]+$/u', $identifier) === 1;
    }

    /**
     * Get the tenant identifier.
     *
     * @return string the tenant identifier
     */
    public function get_identifier(): string {
        return $this->identifier;
    }

    /**
     * Returns the identifier which needs to be used in SQL statements.
     *
     * @return string the tenant identifier for SQL statements
     */
    public function get_sql_identifier(): string {
        return $this->is_default_tenant() ? '' : $this->identifier;
    }

    /**
     * Returns if the current tenant of this object is the default tenant.
     *
     * @return bool true if this tenant is the default tenant
     */
    public function is_default_tenant(): bool {
        return $this->identifier === self::DEFAULT_IDENTIFIER;
    }

    /**
     * Returns if the current tenant of this object is the template tenant.
     *
     * @return bool true if this tenant is the template tenant
     */
    public function is_template_tenant(): bool {
        return $this->identifier === self::TEMPLATE_IDENTIFIER;
    }

    /**
     * Get the tenant context.
     *
     * @return \context the context the tenant is associated with
     */
    public function get_context(): \context {
        return $this->get_custom_tenant()->get_tenant_context();
    }

    /**
     * Creates the custom_tenant hook object and lets other plugins customize (and validate) the tenant.
     *
     * The template tenant does not represent a real tenant, so it is not being passed to other plugins and always keeps
     * the default context and fullname.
     *
     * @return custom_tenant the hook object
     */
    private function get_custom_tenant(): custom_tenant {
        $customtenant = new custom_tenant($this);
        if (!$this->is_template_tenant()) {
            \core\di::get(\core\hook\manager::class)->dispatch($customtenant);
        }
        return $customtenant;
    }

    /**
     * Returns if the tenant is allowed.
     *
     * In this context "allowed" means that the tenant is not being restricted by an admin setting. The template tenant cannot
     * be restricted, because no user belongs to it.
     *
     * @return bool true if the tenant is allowed
     */
    public function is_tenant_allowed(): bool {
        $restricttenants = !empty(get_config('local_ai_manager', 'restricttenants'));
        if (!$restricttenants || $this->is_template_tenant()) {
            return true;
        }
        $allowedtenantsconfig = get_config('local_ai_manager', 'allowedtenants');
        $allowedtenantsconfig = explode(PHP_EOL, $allowedtenantsconfig);
        foreach ($allowedtenantsconfig as $tenant) {
            if ($this->get_identifier() === trim($tenant)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Getter for retrieving/calculating the display name of the tenant.
     *
     * @return string the display name of this tenant
     */
    public function get_fullname(): string {
        return $this->get_custom_tenant()->get_fullname();
    }

    /**
     * Returns the default context for a tenant which is the system context.
     *
     * @return \context the default context of a tenant
     */
    public function get_defaultcontext(): \context {
        return \context_system::instance();
    }

    /**
     * Returns the default fullname for a tenant if not customized.
     * @return string the default tenant fullname
     */
    public function get_defaultfullname(): string {
        return match ($this->identifier) {
            self::DEFAULT_IDENTIFIER => get_string('defaulttenantname', 'local_ai_manager'),
            self::TEMPLATE_IDENTIFIER => get_string('templatetenantname', 'local_ai_manager'),
            default => $this->identifier,
        };
    }
}

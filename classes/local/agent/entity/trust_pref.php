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

/**
 * Persistent entity for user-level tool-trust preferences (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\local\agent\entity;

use core\persistent;

/**
 * Trust preference entity.
 *
 * Records a user's decision to bypass the approval step for a given tool within
 * a given scope (current session, the user account, or tenant-wide).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class trust_pref extends persistent {

    /** Table name. */
    public const TABLE = 'local_ai_manager_trust_prefs';

    /** Scope: only the current PHP session. */
    public const SCOPE_SESSION = 'session';
    /** Scope: every session of the same user. */
    public const SCOPE_USER = 'user';
    /** Scope: tenant-wide (requires local/ai_manager:configuretrust). */
    public const SCOPE_GLOBAL = 'global';

    #[\Override]
    protected static function define_properties(): array {
        return [
            'userid' => [
                'type' => PARAM_INT,
            ],
            'tenantid' => [
                'type' => PARAM_INT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'toolname' => [
                'type' => PARAM_ALPHANUMEXT,
            ],
            'scope' => [
                'type' => PARAM_ALPHA,
                'choices' => [
                    self::SCOPE_SESSION,
                    self::SCOPE_USER,
                    self::SCOPE_GLOBAL,
                ],
            ],
            'session_id' => [
                'type' => PARAM_ALPHANUMEXT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'expires' => [
                'type' => PARAM_INT,
                'default' => 0,
            ],
        ];
    }
}

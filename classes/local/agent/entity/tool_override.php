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
 * Persistent entity for per-tenant tool description overrides (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\local\agent\entity;

use core\persistent;

/**
 * Tool override entity (SPEZ §19).
 *
 * Stores the tenant- or site-wide overrides and additive fields that the
 * {@see \local_ai_manager\agent\tool_description_resolver} layers on top of
 * the hardcoded description emitted by a {@see \local_ai_manager\agent\tool_definition}.
 *
 * A row with `tenantid` = null is a site-wide override; a row with a
 * tenant id scopes to that tenant. The unique index (toolname, tenantid)
 * is enforced by the DB.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_override extends persistent {

    /** Table name. */
    public const TABLE = 'local_ai_manager_tool_overrides';

    #[\Override]
    protected static function define_properties(): array {
        return [
            'toolname' => [
                'type' => PARAM_ALPHANUMEXT,
            ],
            'tenantid' => [
                'type' => PARAM_INT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'llm_description_override' => [
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'describe_for_user_template' => [
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'example_appendix' => [
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'glossary_json' => [
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'enabled' => [
                'type' => PARAM_INT,
                'default' => 1,
            ],
        ];
    }
}

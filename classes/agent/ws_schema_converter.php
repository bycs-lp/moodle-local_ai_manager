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
 * Converts Moodle external_description trees to JSON-Schema (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent;

use core_external\external_description;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Convert Moodle external-service parameter/return descriptions to JSON-Schema Draft 2020-12.
 *
 * Moodle's external_description tree carries type, description, required/optional flag and
 * default value — everything needed to derive a usable JSON-Schema for LLM consumption.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ws_schema_converter {

    /**
     * Map a Moodle PARAM_* constant to a JSON-Schema type.
     *
     * @param string $paramtype
     * @return string one of 'string', 'integer', 'number', 'boolean'
     */
    public static function param_type_to_json_type(string $paramtype): string {
        return match ($paramtype) {
            PARAM_INT, PARAM_INTEGER => 'integer',
            PARAM_FLOAT, PARAM_NUMBER => 'number',
            PARAM_BOOL => 'boolean',
            default => 'string',
        };
    }

    /**
     * Convert an external_description to a JSON-Schema fragment.
     *
     * @param external_description $desc
     * @return array
     */
    public static function convert(external_description $desc): array {
        if ($desc instanceof external_value) {
            $schema = [
                'type' => self::param_type_to_json_type($desc->type),
                'description' => (string) ($desc->desc ?? ''),
            ];
            if ($desc->allownull === NULL_ALLOWED) {
                $schema['type'] = [$schema['type'], 'null'];
            }
            if ($desc->required === VALUE_DEFAULT && $desc->default !== null) {
                $schema['default'] = $desc->default;
            }
            return $schema;
        }

        if ($desc instanceof external_single_structure) {
            $props = [];
            $required = [];
            foreach ($desc->keys as $key => $subdesc) {
                $props[$key] = self::convert($subdesc);
                if ($subdesc instanceof external_value || $subdesc instanceof external_single_structure
                    || $subdesc instanceof external_multiple_structure) {
                    if ($subdesc->required === VALUE_REQUIRED) {
                        $required[] = $key;
                    }
                }
            }
            $schema = [
                'type' => 'object',
                'description' => (string) ($desc->desc ?? ''),
                'properties' => $props,
                'additionalProperties' => false,
            ];
            if (!empty($required)) {
                $schema['required'] = $required;
            }
            return $schema;
        }

        if ($desc instanceof external_multiple_structure) {
            return [
                'type' => 'array',
                'description' => (string) ($desc->desc ?? ''),
                'items' => self::convert($desc->content),
            ];
        }

        // Unknown subtype: fall back to raw string to keep the pipeline alive.
        return ['type' => 'string', 'description' => 'Unknown external description subtype.'];
    }

    /**
     * Extract the list of required property names from a single-structure's keys.
     *
     * @param external_single_structure $struct
     * @return string[]
     */
    public static function required_keys(external_single_structure $struct): array {
        $required = [];
        foreach ($struct->keys as $key => $sub) {
            if (($sub->required ?? VALUE_REQUIRED) === VALUE_REQUIRED) {
                $required[] = $key;
            }
        }
        return $required;
    }
}

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
 * Class for loading/storing course module specific AI manager configurations.
 *
 * @package    local_ai_manager
 * @copyright  2025 AI Manager Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager;

defined('MOODLE_INTERNAL') || die();

use core\persistent;

/**
 * Class for loading/storing course module specific AI manager configurations.
 *
 * @copyright  2025 AI Manager Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cmconfig extends persistent {

    const TABLE = 'local_ai_manager_cmconfig';

    /**
     * Return the definition of the properties of this model.
     *
     * @return array
     */
    protected static function define_properties() {
        return [
            'cmid' => [
                'type' => PARAM_INT,
                'description' => 'Course module id',
            ],
            'usermodified' => [
                'type' => PARAM_INT,
                'default' => 0,
                'description' => 'The user who last modified the record',
            ],
            'intvalue' => [
                'type' => PARAM_INT,
                'null' => NULL_ALLOWED,
                'default' => null,
                'description' => 'Integer values',
            ],
            'stringvalue' => [
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null,
                'description' => 'String values',
            ],
        ];
    }
}

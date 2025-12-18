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

namespace aitool_telli\local;

use stdClass;

/**
 * Helper class for the Telli API subplugin.
 *
 * @package    aitool_telli
 * @copyright  2025 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class utils {
    /**
     * Helper function to retrieve usage and model info data from the Telli API.
     *
     * @param string $apikey the apikey to use
     * @param string $baseurl the base url for the API
     * @return stdClass
     */
    public static function get_api_info(string $apikey, string $baseurl): stdClass {
        $return = new stdClass();
        $apiconnector = \core\di::get(\aitool_telli\local\apihandler::class);
        $apiconnector->init($apikey, $baseurl);
        $return->usage = $apiconnector->get_usage_info();
        $return->models = $apiconnector->get_models_info();
        return $return;
    }

    /**
     * Calculates the whole consumption since a given time.
     *
     * @param int $sincetime the timestamp since when the whole consumption should be calculated
     * @return float the whole consumption in eurocents
     */
    public static function get_whole_consumption(int $sincetime): float {
        global $DB;
        $aggregatesum =
            $DB->get_field_sql(
                'SELECT SUM(value) FROM {aitool_telli_consumption} WHERE type = :type AND timecreated > :sincetime',
                ['type' => 'aggregate', 'sincetime' => $sincetime]
            );
        if (!$aggregatesum) {
            $aggregatesum = 0;
        }
        $maxaggregatetimecreated =
            $DB->get_field_sql(
                'SELECT MAX(timecreated) FROM {aitool_telli_consumption} WHERE type = :type AND timecreated > :sincetime',
                ['type' => 'aggregate', 'sincetime' => $sincetime]
            );
        if (!$maxaggregatetimecreated) {
            $maxaggregatetimecreated = $sincetime;
        }
        $sql = "SELECT MAX(value)FROM {aitool_telli_consumption}
                 WHERE type = :type AND timecreated > :maxaggregatetime";
        $params = ['type' => 'current', 'maxaggregatetime' => $maxaggregatetimecreated];
        $result = $DB->get_field_sql($sql, $params);
        return (float) $aggregatesum + (float) $result;
    }
}

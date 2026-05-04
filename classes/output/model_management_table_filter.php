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

namespace local_ai_manager\output;

use renderer_base;
use stdClass;

/**
 * Class for rendering the filter for the model management table.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class model_management_table_filter extends \core\output\datafilter {
    /**
     * Get data for all filter types.
     *
     * @return array
     */
    protected function get_filtertypes(): array {
        $filtertypes = [];

        $filtertypes[] = $this->get_namepattern_filter();
        $filtertypes[] = $this->get_connector_filter();
        $filtertypes[] = $this->get_deprecated_filter();

        return $filtertypes;
    }

    /**
     * Get data for the namepattern filter (free text search on model name / displayname).
     *
     * @return stdClass the filter object
     */
    protected function get_namepattern_filter(): stdClass {
        return $this->get_filter_object(
            'namepattern',
            get_string('namepattern', 'local_ai_manager'),
            true,
            true,
            'core/datafilter/filtertypes/keyword',
            [],
            true,
            null,
            false,
            [self::JOINTYPE_ANY]
        );
    }

    /**
     * Get data for the connector filter.
     *
     * @return stdClass the filter object
     */
    protected function get_connector_filter(): stdClass {
        $connectors = array_keys(\core_plugin_manager::instance()->get_installed_plugins('aitool'));
        $options = [];
        foreach ($connectors as $connector) {
            $options[] = (object) [
                'value' => $connector,
                'title' => get_string('pluginname', 'aitool_' . $connector),
            ];
        }

        return $this->get_filter_object(
            'connector',
            get_string('model_connectors', 'local_ai_manager'),
            false,
            true,
            null,
            $options,
            false,
            null,
            false,
            [self::JOINTYPE_ANY]
        );
    }

    /**
     * Get data for the deprecated filter.
     *
     * @return stdClass the filter object
     */
    protected function get_deprecated_filter(): stdClass {
        $options = [
            (object) ['value' => 1, 'title' => get_string('yes')],
            (object) ['value' => 0, 'title' => get_string('no')],
        ];

        return $this->get_filter_object(
            'deprecated',
            get_string('model_deprecated', 'local_ai_manager'),
            false,
            false,
            null,
            $options,
            false,
            null,
            false,
            [self::JOINTYPE_ANY]
        );
    }

    /**
     * Export the renderer data in a mustache template friendly format.
     *
     * @param renderer_base $output unused.
     * @return stdClass data in a format compatible with a mustache template.
     */
    public function export_for_template(renderer_base $output): stdClass {
        return (object) [
            'tableregionid' => $this->tableregionid,
            'filtertypes' => $this->get_filtertypes(),
            'rownumber' => 1,
        ];
    }
}

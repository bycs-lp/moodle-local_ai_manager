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

namespace local_ai_manager\table;

use core\context;
use core_table\dynamic;
use core_table\local\filter\filterset;
use html_writer;
use moodle_url;
use stdClass;
use table_sql;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/tablelib.php');

/**
 * Table class for managing AI model definitions.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class model_management_table extends table_sql implements dynamic {
    /**
     * Constructor.
     *
     * @param string $uniqid a unique id to use for the table
     */
    public function __construct($uniqid) {
        parent::__construct($uniqid);

        $this->set_attribute('id', $this->uniqueid);
        $this->define_baseurl(new moodle_url('/local/ai_manager/manage_models.php'));

        $columns = [
            'name', 'displayname', 'mimetypes', 'vision', 'imggen', 'tts', 'stt',
            'connectors', 'deprecated', 'actions',
        ];
        $headers = [
            get_string('model_name', 'local_ai_manager'),
            get_string('model_displayname', 'local_ai_manager'),
            get_string('model_mimetypes', 'local_ai_manager'),
            get_string('model_vision', 'local_ai_manager'),
            get_string('model_imggen', 'local_ai_manager'),
            get_string('model_tts', 'local_ai_manager'),
            get_string('model_stt', 'local_ai_manager'),
            get_string('model_connectors', 'local_ai_manager'),
            get_string('model_deprecated', 'local_ai_manager'),
            get_string('actions'),
        ];

        $this->define_columns($columns);
        $this->define_headers($headers);

        $this->no_sorting('mimetypes');
        $this->no_sorting('connectors');
        $this->no_sorting('actions');
        $this->collapsible(false);
        $this->sortable(true, 'name');

        $filterset = new model_management_table_filterset();
        $this->set_filterset($filterset);
        parent::setup();
    }

    /**
     * Set the SQL for the table, applying the given filterset.
     *
     * @param model_management_table_filterset $filterset the filterset to apply
     */
    public function set_custom_table_sql(model_management_table_filterset $filterset): void {
        global $DB;

        $concat = $DB->sql_group_concat('mp.connector', ', ');
        $fields = "m.id, m.name, m.displayname, m.mimetypes, m.vision, m.imggen, m.tts, m.stt, m.deprecated, "
            . $concat . " AS connectors";
        $from = '{local_ai_manager_model} m LEFT JOIN {local_ai_manager_model_purpose} mp ON mp.modelid = m.id';
        $where = '1 = 1';
        $params = [];

        $filtersql = '';
        $filterparams = [];

        // Apply namepattern filter (free text on name/displayname).
        if ($filterset->has_filter('namepattern')) {
            $namefilter = $filterset->get_filter('namepattern');
            $namesearchstrings = $namefilter->get_filter_values();
            if (!empty($namesearchstrings)) {
                $nameparts = [];
                $i = 0;
                foreach ($namesearchstrings as $searchstring) {
                    $namelike = $DB->sql_like('m.name', ':namepat_n' . $i, false, false);
                    $displaynamelike = $DB->sql_like('m.displayname', ':namepat_d' . $i, false, false);
                    $nameparts[] = '(' . $namelike . ' OR ' . $displaynamelike . ')';
                    $filterparams['namepat_n' . $i] = '%' . $searchstring . '%';
                    $filterparams['namepat_d' . $i] = '%' . $searchstring . '%';
                    $i++;
                }
                $filtersql .= ' AND (' . implode(' AND ', $nameparts) . ')';
            }
        }

        // Apply connector filter.
        if ($filterset->has_filter('connector')) {
            $connectorfilter = $filterset->get_filter('connector');
            $connectorvalues = $connectorfilter->get_filter_values();
            if (!empty($connectorvalues)) {
                [$insql, $inparams] = $DB->get_in_or_equal($connectorvalues, SQL_PARAMS_NAMED, 'conn');
                $filtersql .= ' AND m.id IN (SELECT modelid FROM {local_ai_manager_model_purpose} WHERE connector ' .
                    $insql . ')';
                $filterparams = array_merge($filterparams, $inparams);
            }
        }

        // Apply deprecated filter.
        if ($filterset->has_filter('deprecated')) {
            $deprecatedfilter = $filterset->get_filter('deprecated');
            $deprecatedvalues = $deprecatedfilter->get_filter_values();
            if (count($deprecatedvalues) > 0) {
                [$insql, $inparams] = $DB->get_in_or_equal($deprecatedvalues, SQL_PARAMS_NAMED, 'depr');
                $filtersql .= ' AND m.deprecated ' . $insql;
                $filterparams = array_merge($filterparams, $inparams);
            }
        }

        $groupby = ' GROUP BY m.id, m.name, m.displayname, m.mimetypes, m.vision, m.imggen, m.tts, m.stt, m.deprecated';

        $this->set_sql($fields, $from, $where . $filtersql . $groupby, array_merge($params, $filterparams));

        // Custom count SQL needed because of GROUP BY.
        $this->set_count_sql(
            "SELECT COUNT(*) FROM (SELECT m.id FROM {local_ai_manager_model} m"
            . " LEFT JOIN {local_ai_manager_model_purpose} mp ON mp.modelid = m.id"
            . " WHERE " . $where . $filtersql . $groupby . ") AS subquery",
            array_merge($params, $filterparams)
        );
    }

    /**
     * Render the mimetypes column.
     *
     * @param stdClass $row The current row data
     * @return string The mimetypes string
     */
    public function col_mimetypes(stdClass $row): string {
        if (empty($row->mimetypes)) {
            return '';
        }
        $types = array_map('trim', explode(',', $row->mimetypes));
        sort($types);
        return implode(html_writer::empty_tag('br'), array_map('s', $types));
    }

    /**
     * Render a boolean capability column as checkmark or cross icon.
     *
     * @param bool $value The boolean value
     * @return string The icon HTML
     */
    private function render_boolean_icon(bool $value): string {
        if ($value) {
            return '<i class="fa fa-check text-success" aria-label="' . get_string('yes') . '"></i>';
        }
        return '<i class="fa fa-times text-muted" aria-label="' . get_string('no') . '"></i>';
    }

    /**
     * Render the vision column.
     *
     * @param stdClass $row The current row data
     * @return string The vision icon
     */
    public function col_vision(stdClass $row): string {
        return $this->render_boolean_icon(!empty($row->vision));
    }

    /**
     * Render the imggen column.
     *
     * @param stdClass $row The current row data
     * @return string The imggen icon
     */
    public function col_imggen(stdClass $row): string {
        return $this->render_boolean_icon(!empty($row->imggen));
    }

    /**
     * Render the tts column.
     *
     * @param stdClass $row The current row data
     * @return string The tts icon
     */
    public function col_tts(stdClass $row): string {
        return $this->render_boolean_icon(!empty($row->tts));
    }

    /**
     * Render the stt column.
     *
     * @param stdClass $row The current row data
     * @return string The stt icon
     */
    public function col_stt(stdClass $row): string {
        return $this->render_boolean_icon(!empty($row->stt));
    }

    /**
     * Render the connectors column.
     *
     * @param stdClass $row The current row data
     * @return string The connectors as comma-separated string
     */
    public function col_connectors(stdClass $row): string {
        if (empty($row->connectors)) {
            return '';
        }
        $connectors = array_map('trim', explode(',', $row->connectors));
        $names = array_map(function ($connector) {
            return get_string('pluginname', 'aitool_' . $connector);
        }, $connectors);
        sort($names);
        return s(implode(', ', $names));
    }

    /**
     * Render the deprecated column.
     *
     * @param stdClass $row The current row data
     * @return string The deprecated badge or label
     */
    public function col_deprecated(stdClass $row): string {
        if (!empty($row->deprecated)) {
            return html_writer::span(get_string('yes'), 'badge bg-warning text-dark');
        }
        return get_string('no');
    }

    /**
     * Render the actions column with edit and delete icons.
     *
     * @param stdClass $row The current row data
     * @return string The action icons HTML
     */
    public function col_actions(stdClass $row): string {
        global $OUTPUT;

        $editicon = $OUTPUT->pix_icon('t/edit', get_string('edit'));
        $editlink = html_writer::link(
            '#',
            $editicon,
            [
                'data-action' => 'edit',
                'data-modelid' => $row->id,
                'title' => get_string('edit'),
            ]
        );

        $deleteicon = $OUTPUT->pix_icon('t/delete', get_string('delete'));
        $deletelink = html_writer::link(
            '#',
            $deleteicon,
            [
                'data-action' => 'delete',
                'data-modelid' => $row->id,
                'data-modelname' => $row->name,
                'title' => get_string('delete'),
            ]
        );

        return $editlink . ' ' . $deletelink;
    }

    #[\Override]
    public function set_filterset(filterset $filterset): void {
        if (!($filterset instanceof model_management_table_filterset)) {
            throw new \coding_exception('The filterset must be an instance of model_management_table_filterset');
        }
        $this->set_custom_table_sql($filterset);
        parent::set_filterset($filterset);
    }

    #[\Override]
    public function has_capability(): bool {
        return has_capability('local/ai_manager:managemodels', \context_system::instance());
    }

    #[\Override]
    public function get_context(): context {
        return \context_system::instance();
    }

    #[\Override]
    public function guess_base_url(): void {
        $this->define_baseurl(new moodle_url('/local/ai_manager/manage_models.php'));
    }
}

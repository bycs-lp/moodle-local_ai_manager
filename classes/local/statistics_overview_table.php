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

use moodle_url;
use stdClass;
use table_sql;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/tablelib.php');

/**
 * Table class for showing user statistics.
 *
 * @package    local_ai_manager
 * @copyright  2024 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class statistics_overview_table extends table_sql {
    /**
     * Constructor.
     *
     * @param string $uniqid a uniqid for this table
     * @param moodle_url $baseurl the base url where this table is being rendered
     */
    public function __construct(
        string $uniqid,
        moodle_url $baseurl
    ) {
        global $DB;
        parent::__construct($uniqid);
        $this->set_attribute('id', $uniqid);
        $this->define_baseurl($baseurl);
        // Define the list of columns to show.
        $columns = ['model', 'modelinfo', 'connector', 'requestcount', 'userusage'];
        $headers = [
            get_string('model', 'local_ai_manager'),
            get_string('modeldetailed', 'local_ai_manager'),
            get_string('aitool', 'local_ai_manager'),
            get_string('request_count', 'local_ai_manager'),
            get_string('usage', 'local_ai_manager'),
        ];
        $this->define_columns($columns);
        // Define the titles of columns to show in header.
        $this->define_headers($headers);
        $this->collapsible(false);

        // We need the model and connector as well, but don't show them to the user. This will lead to "multiple"
        // entries for the same modelinfo string if the same modelinfo is used by different connectors as well as "models" in
        // the sense of "gpt-4o" is something different than a hard coded fake modle string "chatgpt_preconfigured_azure" when
        // using azure.
        // We need a unique first column which we combine out of the three grouped columns. We do not display the first column
        // anyway so it does not really matter how it is shaped as long as it is guaranteed to be unique.
        $uniquefirstcolumn = $DB->sql_concat('modelinfo', '"-"', 'model', '"-"', 'connector');
        $fields =
            $uniquefirstcolumn . 'AS id, modelinfo, model, connector, COUNT(modelinfo) AS requestcount, SUM(value) AS userusage';
        $from = '{local_ai_manager_request_log}';
        $tenant = \core\di::get(tenant::class);
        $where = 'tenant = :tenant GROUP BY modelinfo, model, connector';
        $params = ['tenant' => $tenant->get_sql_identifier()];
        $this->set_sql($fields, $from, $where, $params);
        $this->set_count_sql(
            "SELECT COUNT(DISTINCT modelinfo) FROM {local_ai_manager_request_log} WHERE tenant = :tenant",
            $params
        );

        parent::setup();
    }

    /**
     * Get the icon representing the lockes state.
     *
     * @param stdClass $row the data object of the current row
     * @return string the string representation of the userusage column
     */
    public function col_userusage(stdClass $row): string {
        try {
            $connector =
                \core\di::get(connector_factory::class)->get_connector_by_connectorname_and_model($row->connector, $row->model);
        } catch (\coding_exception $e) {
            return intval($row->userusage) . " " . get_string('unknownunit', 'local_ai_manager');
        }
        // Currently there are only requests and tokens as units, so we can use intval for the moment.
        return intval($row->userusage) . " " . $connector->get_unit()->to_string();
    }

    #[\Override]
    public function other_cols($column, $row): ?string {
        if ($column === 'checkbox') {
            return '<input type="checkbox" data-userid="' . $row->id . '"/>';
        }
        return null;
    }
}

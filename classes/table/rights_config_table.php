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
use local_ai_manager\hook\usertable_extend;
use local_ai_manager\hook\usertable_filter;
use local_ai_manager\local\tenant;
use local_ai_manager\local\userinfo;
use moodle_url;
use stdClass;
use table_sql;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/tablelib.php');

/**
 * Table class representing the table for configuring the rights and roles of users in the AI manager.
 *
 * @package    local_ai_manager
 * @copyright  2024 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rights_config_table extends table_sql implements dynamic {

    /** @var tenant The tenant for which the table is being rendered. */
    private tenant $tenant;

    /** @var array array of strings that contains the column names */
    private array $columnnames;

    /** @var array array of strings that contains the header names */
    private array $headernames;

    /**
     * Constructor.
     *
     * @param string $uniqid a unique id to use for the table
     */
    public function __construct($uniqid) {
        global $SESSION;
        parent::__construct($uniqid);

        $this->tenant = $SESSION->local_ai_manager_tenant;

        $this->set_attribute('id', $this->uniqueid);
        $this->define_baseurl(new moodle_url('/local/ai_manager/rights_config.php',
                ['tenant' => $this->tenant->get_identifier()]));;
        // Define the list of columns to show.
        $this->columnnames = ['checkbox', 'lastname', 'firstname', 'role', 'locked', 'scope'];
        $checkboxheader = html_writer::div('', 'rights-table-selection_info', ['id' => 'rights-table-selection_info']);
        $checkboxheader .= html_writer::empty_tag('input', ['type' => 'checkbox', 'id' => 'rights-table-selectall_checkbox']);
        $this->headernames = [
                $checkboxheader,
                get_string('lastname'),
                get_string('firstname'),
                get_string('role', 'local_ai_manager'),
                get_string('locked', 'local_ai_manager'),
                get_string('scope', 'local_ai_manager'),
        ];

        $this->no_sorting('checkbox');
        $this->no_sorting('role');
        $this->no_sorting('scope');
        $this->no_sorting('locked');
        $this->collapsible(false);
        $this->sortable(true, 'lastname');

        if (!empty(get_config('local_ai_manager', 'requireconfirmtou'))) {
            $this->columnnames[] = 'confirmed';
            $this->headernames[] = get_string('confirmed', 'local_ai_manager');
            $this->no_sorting('confirmed');
        }

        $filterset = new rights_config_table_filterset();
        $this->set_filterset($filterset);
        parent::setup();
    }

    /**
     * Function that sets the table SQL and also adds the SQL necessary to apply the filters.
     *
     * @param rights_config_table_filterset $filterset the filterset to use. If you do not want to use any filters, just
     *  create a new rights_config_table_filterset object, do not add any filters to it and pass it to this function
     */
    public function set_custom_table_sql(rights_config_table_filterset $filterset): void {
        global $DB;
        $tenantfield = get_config('local_ai_manager', 'tenantcolumn');

        $fields = 'u.id as id, lastname, firstname, role, locked, ui.confirmed, ui.scope';
        $from =
                '{user} u LEFT JOIN {local_ai_manager_userinfo} ui ON u.id = ui.userid';
        $where = 'u.deleted != 1 AND u.suspended != 1 AND ' . $tenantfield . ' = :tenant';

        $params = ['tenant' => $this->tenant->get_sql_identifier()];

        $usertableextend =
                new usertable_extend($this->tenant, $this->columnnames, $this->headernames, $fields, $from, $where, $params);
        \core\di::get(\core\hook\manager::class)->dispatch($usertableextend);

        $this->define_columns($usertableextend->get_columns());
        // Define the titles of columns to show in header.
        $this->define_headers($usertableextend->get_headers());

        $filtersql = '';
        $filterparams = [];

        $hookfilterwhere = '';
        $rolefilterwhere = '';
        $namepatternfilterwhere = '';

        // If the filter hook is being used, inject the ids the user chose from the hook filter into the hook object,
        // let the hook object calculate the sql snippet and retrieve it from the hook object.
        if ($filterset->has_filter('hook')) {
            $hookfilter = $filterset->get_filter('hook');
            $userfilterhook = new usertable_filter($this->tenant);
            $userfilterhook->set_selected_filterids($hookfilter->get_filter_values());
            \core\di::get(\core\hook\manager::class)->dispatch($userfilterhook);
            $hookfilterwhere = $userfilterhook->get_filter_sql_select();
            $hookfilterwhere = '(' . $hookfilterwhere . ')';
            $filterparams = array_merge($filterparams, $userfilterhook->get_filter_sql_params());
        }
        // If the role filter is being used, calculcate the SQL snippet based on the selected role ids.
        if ($filterset->has_filter('role')) {
            // This is a nightmare concerning performance, but showing the rights config table while also filtering roles does not
            // happen very often, so we should be fine.
            // On the other hand we cannot just apply the filter SQL to the table SQL, because there is no SQL way to determine the
            // roles for users who do not have a userinfo record yet.
            $rolefilter = $filterset->get_filter('role');
            $rolefilterids = $rolefilter->get_filter_values();
            if (!empty($rolefilterids)) {
                $rolefiltersql = "SELECT u.id as userid, ui.role as role FROM {user} u "
                        . "LEFT JOIN {local_ai_manager_userinfo} ui ON u.id = ui.userid "
                        . "WHERE u.deleted <> 1 AND u.suspended <> 1 AND " . $tenantfield . " = :tenant";
                $rolefilterparams = ['tenant' => $this->tenant->get_sql_identifier()];
                $records = $DB->get_records_sql($rolefiltersql, $rolefilterparams);
                $roleuserids = [];
                $rolefilterids = array_map('intval', $rolefilterids);
                foreach ($records as $record) {
                    $userinfo = new userinfo($record->userid);
                    if (in_array($userinfo->get_role(), $rolefilterids)) {
                        $roleuserids[] = $record->userid;
                    }
                }
                if (!empty($roleuserids)) {
                    [$insql, $roleparams] = $DB->get_in_or_equal($roleuserids, SQL_PARAMS_NAMED);
                    $rolefilterwhere = ' u.id ' . $insql;
                } else {
                    // We could not find any user with the roles in the filter, so we need to return no entries.
                    $rolefilterwhere = ' FALSE ';
                    $roleparams = [];
                }
                $rolefilterwhere = '(' . $rolefilterwhere . ')';
                $filterparams = array_merge($filterparams, $roleparams);
            }
        }
        // Finally also apply the namepattern filter if the user is using it.
        if ($filterset->has_filter('namepattern')) {
            $namepatternfilter = $filterset->get_filter('namepattern');

            $namesearchstrings = $namepatternfilter->get_filter_values();
            $namepatternparams = [];
            if (count($namesearchstrings) > 0) {
                // You can apply the filter without confirming your entered string, which will throw a nasty error if we do not
                // catch the case here.
                $i = 0;
                foreach ($namesearchstrings as $namesearchstring) {
                    $lastnamelike = $DB->sql_like('lastname', ':namesearchstring_l' . $i, false, false);
                    $firstnamelike = $DB->sql_like('firstname', ':namesearchstring_f' . $i, false, false);
                    if ($i !== 0) {
                        $namepatternfilterwhere .= ' AND ';
                    }
                    $namepatternfilterwhere .= '(' . $lastnamelike . ' OR ' . $firstnamelike . ')';
                    $namepatternparams['namesearchstring_l' . $i] = '%' . $namesearchstring . '%';
                    $namepatternparams['namesearchstring_f' . $i] = '%' . $namesearchstring . '%';
                    $i++;
                }
                $namepatternfilterwhere = '(' . $namepatternfilterwhere . ')';
                $filterparams = array_merge($filterparams, $namepatternparams);
            }
        }

        // We now have to concatenate the SQL snippets from the different filters depending on the selected join type and
        // depending on the fact which filters actually are currently active.
        $i = 0;
        foreach ([$hookfilterwhere, $namepatternfilterwhere, $rolefilterwhere] as $filterwhere) {
            if (empty($filterwhere)) {
                continue;
            }
            if ($filterset->get_join_type() === \core\output\datafilter::JOINTYPE_NONE) {
                $filterwhere = ' NOT ' . $filterwhere;
            }
            if ($i !== 0) {
                if ($filterset->get_join_type() === \core\output\datafilter::JOINTYPE_ANY) {
                    $filtersql .= ' OR ';
                } else if ($filterset->get_join_type() === \core\output\datafilter::JOINTYPE_ALL ||
                        $filterset->get_join_type() === \core\output\datafilter::JOINTYPE_NONE) {
                    $filtersql .= ' AND ';
                }
            }
            $filtersql .= $filterwhere;
            $i++;
        }

        // If we have some filter sql, wrap it in brackets and put and "AND" before so we can append it to the table
        // SQL "where snippet".
        if (!empty($filtersql)) {
            $filtersql = ' AND (' . $filtersql . ')';
        }

        $this->set_sql($usertableextend->get_fields(), $usertableextend->get_from(),
                $usertableextend->get_where() . $filtersql . ' GROUP BY u.id, role, locked, ui.confirmed, ui.scope',
                array_merge($usertableextend->get_params(), $filterparams));

        // We need to use this because we are using "GROUP BY" which is not being expected by the sql table.
        $this->set_count_sql("SELECT COUNT(*) FROM (SELECT " . $usertableextend->get_fields() . " FROM "
                . $usertableextend->get_from() . " WHERE " . $usertableextend->get_where() . $filtersql .
                " GROUP BY u.id, role, locked, ui.confirmed, ui.scope) AS subquery",
                array_merge($usertableextend->get_params(), $filterparams));
    }

    /**
     * Convert the role identifier to a display name.
     *
     * @param stdClass $value the object containing the information of the current row
     * @return string the resulting string for the role column
     */
    public function col_role(stdClass $value): string {
        $role = $value->role;
        if (empty($role)) {
            $userinfo = new userinfo($value->id);
            $role = $userinfo->get_default_role();
        }
        return get_string(userinfo::get_role_as_string($role), 'local_ai_manager');
    }

    /**
     * Get the icon representing the lockes state.
     *
     * @param stdClass $value the object containing the information of the current row
     * @return string the resulting string for the locked column
     */
    public function col_locked(stdClass $value) {
        if (empty($value->locked)) {
            return '<i class="fa fa-unlock local_ai_manager-green"></i>';
        } else {
            return '<i class="fa fa-lock local_ai_manager-red"></i>';
        }
    }

    /**
     * Get the icon representing the user confirmed state.
     *
     * @param stdClass $value the object containing the information of the current row
     * @return string the resulting string for the confirmed column
     */
    public function col_confirmed($value) {
        if (!empty($value->confirmed)) {
            return '<i class="fa fa-unlock local_ai_manager-green"></i>';
        } else {
            return '<i class="fa fa-lock local_ai_manager-red"></i>';
        }
    }

    /**
     * Get the icon representing the user scope.
     *
     * @param stdClass $value the object containing the information of the current row
     * @return string the resulting string for the confirmed column
     */
    public function col_scope($value) {
        $userinfo = new userinfo($value->id);
        $scope = empty($value->scope) ? $userinfo->get_default_scope() : intval($value->scope);
        switch ($scope) {
            case userinfo::SCOPE_EVERYWHERE:
                return '<i class="fa fa-globe local_ai_manager-green" title="' .
                        get_string('scope_everywhere', 'local_ai_manager') . '"></i>';
            case userinfo::SCOPE_COURSES_ONLY:
                return '<i class="fa fa-graduation-cap local_ai_manager-red" title="' .
                        get_string('scope_courses', 'local_ai_manager') . '"></i>';
            default:
                // Should not happen.
                return 'No scope';
        }
    }

    #[\Override]
    public function other_cols($column, $row) {
        if ($column === 'checkbox') {
            return '<input type="checkbox" data-userid="' . $row->id . '"/>';
        }
        return null;
    }

    #[\Override]
    public function show_hide_link($column, $index) {
        if ($column === 'checkbox') {
            return '';
        }
        return parent::show_hide_link($column, $index);
    }

    #[\Override]
    public function set_filterset(filterset $filterset): void {
        if (!($filterset instanceof rights_config_table_filterset)) {
            throw new \coding_exception('The filterset must be an instance of rights_config_table_filterset');
        }
        $this->set_custom_table_sql($filterset);
        parent::set_filterset($filterset);
    }

    #[\Override]
    public function has_capability(): bool {
        return has_capability('local/ai_manager:manage', $this->tenant->get_context());
    }

    #[\Override]
    public function get_context(): context {
        return $this->tenant->get_context();
    }

    #[\Override]
    public function guess_base_url(): void {
        // We already do this in the constructor, but it's required to overwrite this for dynamic table usage.
        $this->define_baseurl(new moodle_url('/local/ai_manager/rights_config.php',
                ['tenant' => $this->tenant->get_identifier()]));;
    }
}

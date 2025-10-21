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

use html_writer;
use local_ai_manager\hook\usertable_extend;
use moodle_url;
use stdClass;
use table_sql;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/tablelib.php');

/**
 * Table class representing the table for viewing the prompts of users.
 *
 * @package    local_ai_manager
 * @copyright  2024 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class view_prompts_table extends table_sql {
    /** @var array Array of user ids to not show the view prompt link. */
    private array $excludeduserids = [];

    /**
     * Constructor.
     *
     * @param string $uniqid a unique id to use for the table
     * @param tenant $tenant the tenant to display the table for
     * @param moodle_url $baseurl the current base url on which the table is being displayed
     */
    public function __construct(
        string $uniqid,
        tenant $tenant,
        moodle_url $baseurl,
        \context $context
    ) {
        global $DB;
        parent::__construct($uniqid);
        $this->set_attribute('id', $uniqid);
        $this->define_baseurl($baseurl);
        // Define the list of columns to show.
        $columns = ['lastname', 'firstname', 'viewprompts'];
        $headers = [
            get_string('lastname'),
            get_string('firstname'),
            get_string('viewprompts', 'local_ai_manager'),
        ];

        $this->define_headers($headers);
        $this->define_columns($columns);

        $tenantfield = get_config('local_ai_manager', 'tenantcolumn');

        $fields = 'u.id as id, lastname, firstname';
        $from = '{user} u';
        $where = 'u.deleted != 1 AND u.suspended != 1 ';
        $params = [];

        $useridstoexclude = [guest_user()->id, ...array_map(fn($admin) => $admin->id, get_admins())];
        [$notinsql, $notinparams] = $DB->get_in_or_equal($useridstoexclude, SQL_PARAMS_NAMED, 'insql', false);
        $where .= 'AND u.id ' . $notinsql . ' ';
        $params = array_merge($params, $notinparams);

        if ($context instanceof \context_course) {
            $course = get_course($context->instanceid);
            $users = enrol_get_course_users($course->id);

            if (empty($users)) {
                $where .= '';
            } else {
                $userids = array_map(fn($user) => $user->id, $users);
                [$insql, $inparams] =
                    $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'insql');
                $where .= 'AND u.id ' . $insql;
                $params = array_merge($params, $inparams);
            }
        } else if ($context->id === $tenant->get_context()->id) {
            $where .= 'AND u.' . $tenantfield . ' = :tenant';
            $params = array_merge($params, ['tenant' => $tenant->get_sql_identifier()]);
        }

        $privilegedroles = get_config('local_ai_manager', 'privilegedroles');
        if (empty($privilegedroles)) {
            $this->excludeduserids = [];
        } else {
            $rolestoexcludeids = explode(',', $privilegedroles);

            $roleexcludeparams['systemcontextid'] = SYSCONTEXTID;
            [$roleexcludeinsql, $roleexcludeinparams] = $DB->get_in_or_equal($rolestoexcludeids, SQL_PARAMS_NAMED);
            $roleexcludeparams = array_merge($roleexcludeparams, $roleexcludeinparams);
            $roleexcludesql = "SELECT u.id FROM " . $from . " "
                . "LEFT JOIN {role_assignments} ra ON ra.userid = u.id AND ra.contextid = :systemcontextid "
                . "WHERE ra.roleid " . $roleexcludeinsql;
            $this->excludeduserids = $DB->get_fieldset_sql($roleexcludesql, array_merge($params, $roleexcludeparams));
        }

        $this->no_sorting('viewprompts');
        $this->collapsible(false);
        $this->sortable(true, 'lastname');

        $this->set_sql($fields, $from, $where, $params);
        parent::setup();
    }

    #[\Override]
    public function other_cols($column, $row) {
        if ($column === 'viewprompts') {
            if (!in_array($row->id, $this->excludeduserids)) {
                return \core\output\html_writer::tag(
                    'button',
                    \core\output\html_writer::tag('i', '', ['class' => 'fa fa-search-plus']),
                    [
                        'class' => 'btn btn-icon',
                        'data-view-prompts-userid' => $row->id,
                        'data-view-prompts-userdisplayname' => $row->firstname . ' ' . $row->lastname,
                    ]
                );
            } else {
                return '';
            }
        }
        return parent::other_cols($column, $row);
    }
}

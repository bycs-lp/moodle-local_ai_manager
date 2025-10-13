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
use local_ai_manager\base_purpose;
use local_ai_manager\local\tenant;
use local_ai_manager\local\unit;
use moodle_url;
use stdClass;
use table_sql;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/tablelib.php');

/**
 * Table class representing the table showing the user statistics.
 *
 * @package    local_ai_manager
 * @copyright  2024 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class userstats_table extends table_sql implements dynamic {
    /** @var bool if the names of the user should be shown (otherwise they will be anonymized). */
    private bool $shownames;

    /** @var array stores the privileged role ids: Users with these role assignments have to be anonymized. */
    private array $privilegedroles;

    /**
     * Constructor.
     *
     * @param string $uniqid a unique id for the table to use
     */
    public function __construct(
        string $uniqid,
    ) {
        global $SESSION;
        parent::__construct($uniqid);
        $tenant = $SESSION->local_ai_manager_tenant;

        $this->set_attribute('id', $uniqid);
        $purpose = $SESSION->local_ai_manager_statistics_purpose ?? null;
        $baseurl = empty($purpose)
            ? new moodle_url('/local/ai_manager/user_statisticss.php', ['tenant' => $tenant->get_identifier()])
            : new moodle_url(
                '/local/ai_manager/purpose_statistics.php',
                ['tenant' => $tenant->get_identifier(), 'purpose' => $purpose]
            );
        $this->define_baseurl($baseurl);
        // Define the list of columns to show.
        $columns = ['lastname', 'firstname'];
        $headers = [
            get_string('lastname'),
            get_string('firstname'),
        ];
        if (has_capability('local/ai_manager:viewusage', $tenant->get_context())) {
            $columns[] = 'requestcount';
            $headers[] = get_string('request_count', 'local_ai_manager');
            if (!empty($purpose)) {
                // phpcs:disable moodle.Commenting.TodoComment.MissingInfoInline
                /* TODO This is actually a bad thing: When designing the structure we did not think of the case that for a purpose
                    there can be multiple connectors with different units.
                    So for now we hardcode the purposes assuming they are stable in terms of which units their connectors are using.
                */
                // phpcs:enable moodle.Commenting.TodoComment.MissingInfoInline
                if (
                    in_array(
                        $purpose,
                        array_filter(
                            base_purpose::get_all_purposes(),
                            fn($purposecandidate) => !in_array($purposecandidate, ['imggen', 'tts'])
                        )
                    )
                ) {
                    $columns[] = 'tokens';
                    $headers[] = unit::TOKEN->to_string();
                }
            }
        }

        $this->define_columns($columns);
        // Define the titles of columns to show in header.
        $this->define_headers($headers);
        $this->collapsible(false);
        $this->sortable(true, 'lastname');

        if (!empty($purpose)) {
            $fields = 'u.id as id, lastname, firstname, COUNT(value) AS requestcount, SUM(value) AS tokens';
            $from = '{local_ai_manager_request_log} rl '
                . 'LEFT JOIN {user} u ON u.id = rl.userid';
            $where = 'rl.tenant = :tenant AND purpose = :purpose GROUP BY u.id, lastname, firstname';
            $params = [
                'tenant' => $tenant->get_sql_identifier(),
                'purpose' => $purpose,
            ];
            $this->set_count_sql(
                "SELECT COUNT(DISTINCT userid) FROM {local_ai_manager_request_log}"
                . " WHERE tenant = :tenant AND purpose = :purpose",
                $params
            );
        } else {
            $fields = 'u.id as id, lastname, firstname, COUNT(value) AS requestcount';
            $from = '{local_ai_manager_request_log} rl LEFT JOIN {user} u ON rl.userid = u.id';
            $where = 'rl.tenant = :tenant GROUP BY u.id, lastname, firstname';
            $params = ['tenant' => $tenant->get_sql_identifier()];
            $this->set_count_sql(
                "SELECT COUNT(DISTINCT userid) FROM {local_ai_manager_request_log} WHERE tenant = :tenant",
                $params
            );
        }
        $this->set_sql($fields, $from, $where, $params);
        parent::setup();

        $this->shownames = has_capability('local/ai_manager:viewusernames', $tenant->get_context());
        $this->privilegedroles = explode(',', get_config('local_ai_manager', 'privilegedroles'));

        $filterset = new userstats_table_filterset();
        $this->set_filterset($filterset);
    }

    /**
     * Get the eventually anonymized last name of the user.
     *
     * @param stdClass $value the object containing the information of the current row
     * @return string the resulting string for the lastname column
     */
    public function col_lastname(stdClass $value): string {
        $userhasprivilegedrole = is_siteadmin($value->id) ||
            array_reduce(
                $this->privilegedroles,
                fn($acc, $cur) => $acc || user_has_role_assignment($value->id, $cur, SYSCONTEXTID)
            );
        if (is_siteadmin() || ($this->shownames && !$userhasprivilegedrole)) {
            return $value->lastname;
        } else {
            return get_string('anonymized', 'local_ai_manager');
        }
    }

    /**
     * Get the eventually anonymized first name of the user.
     *
     * @param stdClass $value the object containing the information of the current row
     * @return string the resulting string for the firstname column
     */
    public function col_firstname(stdClass $value): string {
        $userhasprivilegedrole = is_siteadmin($value->id) ||
            array_reduce(
                $this->privilegedroles,
                fn($acc, $cur) => $acc || user_has_role_assignment($value->id, $cur, SYSCONTEXTID)
            );
        if (is_siteadmin() || ($this->shownames && !$userhasprivilegedrole)) {
            return $value->firstname;
        } else {
            return get_string('anonymized', 'local_ai_manager');
        }
    }

    /**
     * Get the string representation of the current usage.
     *
     * @param stdClass $value the object containing the information of the current row
     * @return string the resulting string for the lastname column
     */
    public function col_tokens(stdClass $value): string {
        return strval(intval($value->tokens));
    }

    #[\Override]
    public function get_context(): context {
        $tenant = \core\di::get(tenant::class);
        return $tenant->get_context();
    }

    #[\Override]
    public function has_capability(): bool {
        $tenant = \core\di::get(tenant::class);
        return has_capability('local/ai_manager:manage', $tenant->get_context());
    }

    #[\Override]
    public function guess_base_url(): void {
        // We already do this in the constructor, but it's required to overwrite this for dynamic table usage.
        $tenant = \core\di::get(tenant::class);
        $baseurl = empty($purpose)
            ? new moodle_url('/local/ai_manager/user_statisticss.php', ['tenant' => $tenant->get_identifier()])
            : new moodle_url(
                '/local/ai_manager/purpose_statistics.php',
                ['tenant' => $tenant->get_identifier(), 'purpose' => $purpose]
            );
        $this->define_baseurl($baseurl);
    }
}

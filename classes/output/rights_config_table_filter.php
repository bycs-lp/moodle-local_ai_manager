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

use core\hook\manager as hook_manager;
use local_ai_manager\hook\usertable_filter;
use local_ai_manager\local\tenant;
use local_ai_manager\local\userinfo;
use renderer_base;
use stdClass;

/**
 * Class for rendering the filter for the rights config table in local_ai_manager.
 *
 * @package    local_ai_manager
 * @copyright  2025 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rights_config_table_filter extends \core\output\datafilter {
    /**
     * Get data for all filter types.
     *
     * @return array
     */
    protected function get_filtertypes(): array {
        $filtertypes = [];

        $filtertypes[] = $this->get_namepattern_filter();

        if ($filtertype = $this->get_roles_filter()) {
            $filtertypes[] = $filtertype;
        }

        if ($filtertype = $this->get_hook_filter()) {
            $filtertypes[] = $filtertype;
        }

        return $filtertypes;
    }

    /**
     * Get data for the roles filter.
     *
     * @return stdClass|null the filter object or null
     */
    protected function get_roles_filter(): ?stdClass {
        $rolefilteroptions =
            [
                userinfo::ROLE_BASIC => get_string(userinfo::get_role_as_string(userinfo::ROLE_BASIC), 'local_ai_manager'),
                userinfo::ROLE_EXTENDED => get_string(
                    userinfo::get_role_as_string(userinfo::ROLE_EXTENDED),
                    'local_ai_manager'
                ),
                userinfo::ROLE_UNLIMITED => get_string(
                    userinfo::get_role_as_string(userinfo::ROLE_UNLIMITED),
                    'local_ai_manager'
                ),
            ];

        return $this->get_filter_object(
            'role',
            get_string('roles', 'core_role'),
            false,
            true,
            null,
            array_map(
                function ($id, $title) {
                    return (object) [
                        'value' => $id,
                        'title' => $title,
                    ];
                },
                array_keys($rolefilteroptions),
                array_values($rolefilteroptions)
            ),
            false,
            null,
            false,
            [self::JOINTYPE_ANY]
        );
    }

    /**
     * Get data for the roles filter.
     *
     * @return stdClass|null the filter object or null
     */
    protected function get_hook_filter(): ?stdClass {
        $usertablefilterhook = new usertable_filter(\core\di::get(tenant::class));
        \core\di::get(hook_manager::class)->dispatch($usertablefilterhook);
        $hookfilteroptions = $usertablefilterhook->get_filter_options();

        return $this->get_filter_object(
            'hook',
            $usertablefilterhook->get_filter_label(),
            false,
            true,
            null,
            array_map(
                function ($id, $title) {
                    return (object) [
                        'value' => $id,
                        'title' => $title,
                    ];
                },
                array_keys($hookfilteroptions),
                array_values($hookfilteroptions)
            ),
            false,
            null,
            false,
            [self::JOINTYPE_ANY]
        );
    }

    /**
     * Get data for the namepattern filter.
     *
     * @return stdClass|null the filter object or null
     */
    protected function get_namepattern_filter(): ?stdClass {
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

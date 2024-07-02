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
 * Settings for the local_ai_manager plugin.
 *
 * @package    local_ai_manager
 * @copyright  2024 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

if ($hassiteconfig) {

    $ADMIN->add('localplugins', new admin_category('local_ai_manager_settings',
            new lang_string('pluginname', 'local_ai_manager')));
    $settings = new admin_settingpage('local_ai_manager', get_string('pluginname', 'local_ai_manager'));
    $ADMIN->add('localplugins', $settings);

    if ($ADMIN->fulltree) {
        $settings->add(
                new admin_setting_heading('local_ai_manager/basicsettings',
                        get_string('basicsettings', 'local_ai_manager'),
                        get_string('basicsettingsdesc', 'local_ai_manager')));

        $settings->add(new admin_setting_configcheckbox(
                'local_ai_manager/addnavigationentry',
                new lang_string('addnavigationentry', 'local_ai_manager'),
                new lang_string('addnavigationentrydesc', 'local_ai_manager'),
                1
        ));
    }
}

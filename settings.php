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

use local_ai_manager\local\admin_setting_configdate;

defined('MOODLE_INTERNAL') || die;

global $DB;

if ($hassiteconfig) {

    $aimanagercategory = new admin_category('local_ai_manager_settings',
            new lang_string('pluginname', 'local_ai_manager'));
    $ADMIN->add('localplugins', $aimanagercategory);
    $settings = new admin_settingpage('local_ai_manager', get_string('basicsettings', 'local_ai_manager'));
    $ADMIN->add('local_ai_manager_settings', $settings);

    if ($ADMIN->fulltree) {
        $settings->add(
                new admin_setting_heading('local_ai_manager/basicsettings',
                        get_string('basicsettings', 'local_ai_manager'),
                        get_string('basicsettingsdesc', 'local_ai_manager')));

        $settings->add(new admin_setting_configselect('local_ai_manager/tenantcolumn',
                new lang_string('tenantcolumn', 'local_ai_manager'),
                new lang_string('tenantcolumndesc', 'local_ai_manager'),
                'institution',
                [
                        'institution' => 'institution',
                        'department' => 'department',
                        'city' => 'city',
                ]
        ));

        $settings->add(new admin_setting_configcheckbox(
                'local_ai_manager/addnavigationentry',
                new lang_string('addnavigationentry', 'local_ai_manager'),
                new lang_string('addnavigationentrydesc', 'local_ai_manager'),
                1
        ));

        $settings->add(new admin_setting_configcheckbox(
                'local_ai_manager/verifyssl',
                new lang_string('verifyssl', 'local_ai_manager'),
                new lang_string('verifyssldesc', 'local_ai_manager'),
                1
        ));

        $settings->add(new admin_setting_configcheckbox(
                'local_ai_manager/restricttenants',
                new lang_string('restricttenants', 'local_ai_manager'),
                new lang_string('restricttenants', 'local_ai_manager'),
                0
        ));

        $settings->add(new admin_setting_configtextarea(
                'local_ai_manager/allowedtenants',
                new lang_string('allowedtenants', 'local_ai_manager'),
                new lang_string('allowedtenantsdesc', 'local_ai_manager'),
                ''
        ));

        $settings->add(new admin_setting_configtext(
                'local_ai_manager/requesttimeout',
                new lang_string('requesttimeout', 'local_ai_manager'),
                new lang_string('requesttimeoutdesc', 'local_ai_manager'),
                '60'
        ));

        $settings->add(new admin_setting_configcheckbox(
                'local_ai_manager/requireconfirmtou',
                new lang_string('requireconfirmtou', 'local_ai_manager'),
                new lang_string('requireconfirmtoudesc', 'local_ai_manager'),
                0
        ));

        $settings->add(new admin_setting_confightmleditor(
                'local_ai_manager/termsofuse',
                new lang_string('termsofusesetting', 'local_ai_manager'),
                new lang_string('termsofusesettingdesc', 'local_ai_manager'),
                '',
                PARAM_RAW,
                60,
                20
        ));

        $settings->add(new admin_setting_configtext(
                'local_ai_manager/aiwarningurl',
                new lang_string('aiwarningurl', 'local_ai_manager'),
                new lang_string('aiwarningurldesc', 'local_ai_manager'),
                ''
        ));

        $roleids = get_roles_for_contextlevels(CONTEXT_SYSTEM);
        if (empty($roleids)) {
            $roles = [];
        } else {
            [$insql, $inparams] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED);
            $roles = $DB->get_records_select('role', "id $insql", $inparams, 'shortname');
        }
        $roles = role_fix_names($roles, null, ROLENAME_BOTH, true);
        $settings->add(new admin_setting_configmultiselect('local_ai_manager/privilegedroles',
                get_string('privilegedroles', 'local_ai_manager'),
                get_string('privilegedrolesdesc', 'local_ai_manager'),
                ['manager'],
                $roles
        ));

        $settings->add(new admin_setting_configcheckbox('local_ai_manager/enablecleanuprequestlogtask',
            get_string('enablecleanuprequestlogtask', 'local_ai_manager'),
            get_string('enablecleanuprequestlogtaskdesc', 'local_ai_manager'),
        '0'
        ));

        $settings->add(new admin_setting_configdate('local_ai_manager/datawiperanonymizedate',
                get_string('datawiperanonymizedate', 'local_ai_manager'),
                get_string('datawiperanonymizedatedesc', 'local_ai_manager'),
                '1759269600'
        ));
        $settings->add(new admin_setting_configdate('local_ai_manager/datawiperdeletedate',
                get_string('datawiperdeletedate', 'local_ai_manager'),
                get_string('datawiperdeletedatedesc', 'local_ai_manager'),
                '1759269600'
        ));
    }

    $aitoolssettingpage =
            new admin_settingpage('aitoolpluginsmanagement', get_string('subplugintype_aitool_plural', 'local_ai_manager'));
    $aitoolssettingpage->add(new \core_admin\admin\admin_setting_plugin_manager(
            'aitool',
            \local_ai_manager\table\aitools_admin_table::class,
            'aitools_management',
            get_string('subplugintype_aitool_plural', 'local_ai_manager')
    ));

    $aipurposessettingpage =
            new admin_settingpage('aipurposepluginsmanagement', get_string('subplugintype_aipurpose_plural', 'local_ai_manager'));
    $aipurposessettingpage->add(new \core_admin\admin\admin_setting_plugin_manager(
            'aipurpose',
            \local_ai_manager\table\aipurposes_admin_table::class,
            'aipurposes_management',
            get_string('subplugintype_aitool_plural', 'local_ai_manager')
    ));

    $ADMIN->add('local_ai_manager_settings', $aitoolssettingpage);
    $ADMIN->add('local_ai_manager_settings', $aipurposessettingpage);

    $ADMIN->add('local_ai_manager_settings', new admin_category('aitoolplugins',
            new lang_string('aitoolplugins', 'local_ai_manager')));
    $plugins = \core_plugin_manager::instance()->get_plugins_of_type('aitool');
    foreach ($plugins as $plugin) {
        $plugin->load_settings($ADMIN, 'aitoolplugins', $hassiteconfig);
    }

    $ADMIN->add('local_ai_manager_settings', new admin_category('aipurposeplugins',
            new lang_string('aipurposeplugins', 'local_ai_manager')));
    $plugins = \core_plugin_manager::instance()->get_plugins_of_type('aipurpose');
    foreach ($plugins as $plugin) {
        $plugin->load_settings($ADMIN, 'aipurposeplugins', $hassiteconfig);
    }
}

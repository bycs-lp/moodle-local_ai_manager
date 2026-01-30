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

namespace local_ai_manager\plugininfo;

use core\plugininfo\base;
use core_component;
use core_plugin_manager;
use local_ai_manager\base_connector;

/**
 * Plugininfo class for the subplugintype aitool.
 *
 * @package     local_ai_manager
 * @copyright   2024 ISB Bayern
 * @author      Dr. Peter Mayer
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class aitool extends base {
    #[\Override]
    public static function get_enabled_plugins() {
        global $DB;

        $plugins = core_plugin_manager::instance()->get_installed_plugins('aitool');
        if (!$plugins) {
            return [];
        }
        $installed = [];
        foreach ($plugins as $plugin => $version) {
            $installed[] = 'aitool_' . $plugin;
        }

        [$insql, $params] = $DB->get_in_or_equal($installed, SQL_PARAMS_NAMED);
        $disabled = $DB->get_records_select(
            'config_plugins',
            "plugin $insql AND name = 'enabled' AND value = '0'",
            $params,
            'plugin ASC'
        );
        foreach ($disabled as $conf) {
            unset($plugins[explode('_', $conf->plugin, 2)[1]]);
        }

        $enabled = [];
        foreach ($plugins as $plugin => $version) {
            $enabled[$plugin] = $plugin;
        }

        return $enabled;
    }

    #[\Override]
    public static function enable_plugin(string $pluginname, int $enabled): bool {
        $haschanged = false;

        $plugin = 'aitool_' . $pluginname;
        $oldvalue = get_config($plugin, 'enabled');

        // Only set value if there is no config setting or if the value is different from the previous one.
        if ($oldvalue === false || (intval($oldvalue) !== $enabled)) {
            set_config('enabled', $enabled, $plugin);
            $haschanged = true;

            add_to_config_log('enabled', $oldvalue, $enabled, $plugin);
            \core_plugin_manager::reset_caches();
        }

        return $haschanged;
    }

    #[\Override]
    public function is_uninstall_allowed() {
        return true;
    }

    #[\Override]
    public function get_settings_section_name() {
        return $this->type . '_' . $this->name;
    }

    #[\Override]
    public function load_settings(\part_of_admin_tree $adminroot, $parentnodename, $hassiteconfig) {
        global $CFG, $USER, $DB, $OUTPUT, $PAGE; // In case settings.php wants to refer to them.
        $ADMIN = $adminroot; // May be used in settings.php.
        $plugininfo = $this; // Also can be used inside settings.php.

        if (!$this->is_installed_and_upgraded()) {
            return;
        }

        if (!$hassiteconfig || !file_exists($this->full_path('settings.php'))) {
            return;
        }

        $section = $this->get_settings_section_name();

        $settings = new \admin_settingpage($section, $this->displayname, 'moodle/site:config', $this->is_enabled() === false);

        if ($adminroot->fulltree) {
            $shortsubtype = substr($this->type, strlen('aitool'));
            include($this->full_path('settings.php'));
        }

        $adminroot->add($parentnodename, $settings);
    }

    #[\Override]
    public function uninstall(\progress_trace $progress) {
        global $DB;
        $deletedinstanceids = $DB->get_fieldset('local_ai_manager_instance', 'id', ['connector' => $this->name]);
        $DB->delete_records('local_ai_manager_instance', ['connector' => $this->name]);

        if (empty($deletedinstanceids)) {
            return true;
        }
        $sqllike = $DB->sql_like('configkey', '?');
        $params = ['purpose_%_tool'];
        $select = $sqllike;
        [$insql, $inparams] = $DB->get_in_or_equal($deletedinstanceids);
        $params = array_merge($params, $inparams);
        $select = $select . ' AND configvalue ' . $insql;

        $DB->delete_records_select('local_ai_manager_config', $select, $params);
        return true;
    }

    /**
     * Get the component name of the aitool plugin by passing a connector object.
     *
     * @param base_connector $connector The connector object to return the component name for
     * @return string the component name as string, for example 'aitool_gemini'
     */
    public static function get_component_name_by_connector(base_connector $connector): string {
        $classname = get_class($connector);
        return core_component::get_component_from_classname($classname);
    }
}

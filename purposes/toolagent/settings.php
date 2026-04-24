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
 * Settings for aipurpose_toolagent.
 *
 * @package    aipurpose_toolagent
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

if ($hassiteconfig) {
    $settings->add(new admin_setting_configtextarea(
        'aipurpose_toolagent/system_prompt_template',
        new lang_string('setting_system_prompt_template', 'aipurpose_toolagent'),
        new lang_string('setting_system_prompt_template_desc', 'aipurpose_toolagent'),
        get_string('default_system_prompt', 'aipurpose_toolagent'),
        PARAM_RAW
    ));

    $settings->add(new admin_setting_configtext(
        'aipurpose_toolagent/max_tools_in_context',
        new lang_string('setting_max_tools_in_context', 'aipurpose_toolagent'),
        new lang_string('setting_max_tools_in_context_desc', 'aipurpose_toolagent'),
        '15',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configselect(
        'aipurpose_toolagent/tool_selection_strategy',
        new lang_string('setting_tool_selection_strategy', 'aipurpose_toolagent'),
        new lang_string('setting_tool_selection_strategy_desc', 'aipurpose_toolagent'),
        'flat',
        [
            'flat' => get_string('strategy_flat', 'aipurpose_toolagent'),
            'category_gated' => get_string('strategy_category_gated', 'aipurpose_toolagent'),
            'retrieval_gated' => get_string('strategy_retrieval_gated', 'aipurpose_toolagent'),
        ]
    ));
}

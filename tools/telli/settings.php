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
 * Settings for aitool_telli.
 *
 * @package    aitool_telli
 * @copyright  2025 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

global $CFG;

if ($hassiteconfig) {

    $settings->add(new admin_setting_configtext('aitool_telli/baseurl',
            new lang_string('baseurlsetting', 'aitool_telli'),
            new lang_string('baseurlsettingdesc', 'aitool_telli'), ''));

    $settings->add(new admin_setting_configtext('aitool_telli/globalapikey',
            new lang_string('globalapikeysetting', 'aitool_telli'),
            new lang_string('globalapikeysettingdesc', 'aitool_telli'), ''));

    $settings->add(new admin_setting_configtextarea('aitool_telli/availablemodels',
            new lang_string('availablemodelssetting', 'aitool_telli'),
            new lang_string('availablemodelssettingdesc', 'aitool_telli'),
            "gpt-3.5-turbo\n"
            . "gpt-4-turbo#VISION\n"
            . "gpt-4o-mini#VISION\n"
            . "gpt-4o-mini#VISION\n"
            . "meta-llama/CodeLlama-13b-Instruct-hf\n"
            . "meta-llama/Meta-Llama-3.1-405B-Instruct-FP8\n"
            . "meta-llama/Meta-Llama-3.1-70B-Instruct\n"
            . "meta-llama/Meta-Llama-3.1-8B-Instruct\n"
            . "mistralai/Mistral-7B-Instruct-v0.3\n"
            . "mistralai/Mixtral-8x7B-Instruct-v0.1"
    ));

    $settings->add(new admin_setting_description('aitool_telli/managementsitebutton',
            get_string('managementpage', 'aitool_telli'),
            '<p><a class="btn btn-secondary" href="' . $CFG->wwwroot . '/local/ai_manager/tools/telli/management.php">'
            . get_string('managementpagelink', 'aitool_telli')
            . '</a></p><p>'
            . get_string('managementpagelinkdesc', 'aitool_telli')
            . '</p>'
    ));
}

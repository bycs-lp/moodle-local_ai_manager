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
    $settings->add(
        new admin_setting_configtext(
            'aitool_telli/baseurl',
            new lang_string('baseurlsetting', 'aitool_telli'),
            new lang_string('baseurlsettingdesc', 'aitool_telli'),
            ''
        )
    );

    $settings->add(
        new admin_setting_configtext(
            'aitool_telli/globalapikey',
            new lang_string('globalapikeysetting', 'aitool_telli'),
            new lang_string('globalapikeysettingdesc', 'aitool_telli'),
            ''
        )
    );

    $settings->add(
        new admin_setting_configtextarea(
            'aitool_telli/availablemodels',
            new lang_string('availablemodelssetting', 'aitool_telli'),
            new lang_string('availablemodelssettingdesc', 'aitool_telli'),
            "meta-llama/Meta-Llama-3.1-8B-Instruct\n"
            . "gpt-4-turbo\n"
            . "gpt-3.5-turbo\n"
            . "meta-llama/CodeLlama-13b-Instruct-hf\n"
            . "mistralai/Mistral-7B-Instruct-v0.3\n"
            . "mistralai/Mixtral-8x7B-Instruct-v0.1\n"
            . "meta-llama/Meta-Llama-3.1-405B-Instruct-FP8\n"
            . "BAAI/bge-m3\n"
            . "gpt-4o#VISION\n"
            . "dall-e-3#IMGGEN\n"
            . "stabilityai/stable-diffusion-xl-base-1.0#IMGGEN\n"
            . "black-forest-labs/FLUX.1-schnell#IMGGEN"
        )
    );

    $settings->add(
        new admin_setting_configduration(
            'aitool_telli/retentionperiod',
            new lang_string('retentionperiodsetting', 'aitool_telli'),
            new lang_string('retentionperiodsettingdesc', 'aitool_telli'),
            2 * YEARSECS,
            YEARSECS
        )
    );

    $settings->add(
        new admin_setting_description(
            'aitool_telli/managementsitebutton',
            get_string('managementpage', 'aitool_telli'),
            '<p><a class="btn btn-secondary" href="' . $CFG->wwwroot . '/local/ai_manager/tools/telli/management.php">'
            . get_string('managementpagelink', 'aitool_telli')
            . '</a></p><p>'
            . get_string('managementpagelinkdesc', 'aitool_telli')
            . '</p>'
        )
    );
}

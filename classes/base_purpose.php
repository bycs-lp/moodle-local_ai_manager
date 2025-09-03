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

namespace local_ai_manager;

use core_plugin_manager;
use local_ai_manager\local\userinfo;

/**
 * Base class for purpose subplugins.
 *
 * @package    local_ai_manager
 * @copyright  ISB Bayern, 2024
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class base_purpose {

    /** @var string Constant for defining that a purpose option is an array */
    const PARAM_ARRAY = 'array';

    /**
     * Returns a localized description of the purpose.
     *
     * @return string the localized string describing the purpose and what it's supposed to be used for
     */
    public function get_description(): string {
        return get_string('purposedescription', 'aipurpose_' . $this->get_plugin_name());
    }

    /**
     * Getter for the request options.
     *
     * @param array $options the current options which can be filtered/manipulated etc.
     * @return array the eventually manipulated options array
     */
    final public function get_request_options(array $options): array {
        $newoptions = [];
        if (!empty($options['itemid'])) {
            $newoptions['itemid'] = $options['itemid'];
        }
        if (!empty($options['forcenewitemid'])) {
            $newoptions['forcenewitemid'] = $options['forcenewitemid'];
        }
        return $newoptions + $this->get_additional_request_options($options);
    }

    /**
     * Function that can be used by subclasses to manipulate the options being sent in a request.
     *
     * Subclasses can override this function and manipulate the options being sent in a request to the
     * needs of the specific purpose. The default is to just use all options. The options are being sanitized before
     * by using {@see self::get_available_purpose_options}.
     *
     * @param array $options the options being sent in the request
     * @return array the manipulated options
     */
    public function get_additional_request_options(array $options): array {
        return $options;
    }

    /**
     * Returns all enabled purpose subplugins.
     *
     * @return array array of purpose subplugin names
     */
    public static function get_all_purposes(): array {
        return core_plugin_manager::instance()->get_enabled_plugins('aipurpose');
    }

    /**
     * Returns the name of the config key for storing the configured tool for a given purpose.
     *
     * @param string $purpose the purpose name
     * @param int $role the local_ai_manager internal role to retrieve the config key for
     * @return string the config key for storing the config setting for accessing the config via the config manager
     */
    public static function get_purpose_tool_config_key(string $purpose, int $role): string {
        // Currently, userinfo::ROLE_EXTENDED and userinfo::ROLE_UNLIMITED are handled equally.
        if ($role === userinfo::ROLE_UNLIMITED) {
            $role = userinfo::ROLE_EXTENDED;
        }
        return 'purpose_' . $purpose . '_tool_' . userinfo::get_role_as_string($role);
    }

    /**
     * Helper function for determining the plugin name based on this object.
     *
     * @return string the plugin name
     */
    final public function get_plugin_name(): string {
        return preg_replace('/^aipurpose_(.*)\\\\.*/', '$1', get_class($this));
    }

    /**
     * Get the options defined by this purpose.
     *
     * @return array associative array defining the options
     * @throws \coding_exception in case that a subclass tries to define an option which is already being defined in the
     *  parent class
     */
    final public function get_available_purpose_options(): array {
        $options = [];
        $options['itemid'] = PARAM_INT;
        $options['forcenewitemid'] = PARAM_BOOL;
        $additionalpurposeoptions = $this->get_additional_purpose_options();
        foreach (array_keys($additionalpurposeoptions) as $purposeoption) {
            if (in_array($purposeoption, $options)) {
                throw new \coding_exception('You must not define options in the purpose subclass which are being used in the '
                . 'base class.');
            }
        }
        return $options + $additionalpurposeoptions;
    }

    /**
     * Function to define purpose options.
     *
     * Should be overwritten of subclasses if they want to add options.
     *
     * @return array the options array
     */
    public function get_additional_purpose_options(): array {
        return [];
    }

    /**
     * Most AI tools will return Markdown code, so we use this as default.
     *
     * Can be overwritten by purposes which return special content, for example single strings which should not be wrapped
     * or cleaned.
     *
     * @param string $output the output/result from the API of the AI tool
     * @return string the formatted output
     */
    public function format_output(string $output): string {
        // We need to additionally escape some sequences so mathjax filter can be applied properly in the frontend.
        $output = str_replace('\\(', '\\\\(', $output);
        $output = str_replace('\\)', '\\\\)', $output);
        $output = str_replace('\\[', '\\\\[', $output);
        $output = str_replace('\\]', '\\\\]', $output);
        return format_text($output, FORMAT_MARKDOWN, ['filter' => false]);
    }

    /**
     * Formats the given prompt text based on the provided sanitized options.
     *
     * @param string $prompttext The prompt text to be formatted.
     * @param array $sanitizedoptions An array of sanitized options for formatting.
     *
     * @return array The formatted prompt text as an array.
     */
    public function format_prompt_text(string $prompttext, array $sanitizedoptions): string {
        return $prompttext;
    }
}

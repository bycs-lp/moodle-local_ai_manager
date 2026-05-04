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

namespace aipurpose_itt;

use coding_exception;
use local_ai_manager\base_connector;
use local_ai_manager\base_purpose;
use local_ai_manager\local\connector_factory;
use local_ai_manager\local\userinfo;

/**
 * Purpose itt methods.
 *
 * @package    aipurpose_itt
 * @copyright  2024 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purpose extends base_purpose {
    /**
     * Returns the output as sanitized plain text.
     *
     * The ITT output can be consumed by other plugins (e.g. assignfeedback_aif,
     * qbank_questiongen) which eventually will use it for further (LLM) processing.
     * That's why there should not be any markdown to HTML conversion at this point.
     * CARE: The LLM will eventually return Markdown anyway. If this is not what
     * the plugin expects/wants this can be controlled by the prompt (e.g. "do not use
     * any formatting like Markdown for output"). 
     *
     * @param string $output the output/result from the API of the AI tool
     * @return string the sanitized plain text output
     */
    #[\Override]
    public function format_output(string $output): string {
        return clean_text($output);
    }

    #[\Override]
    public function get_additional_purpose_options(): array {
        global $USER;
        $userinfo = new userinfo($USER->id);
        $factory = \core\di::get(connector_factory::class);
        $connector = $factory->get_connector_by_purpose($this->get_plugin_name(), $userinfo->get_role());
        $instance = $connector->get_instance();
        if (!in_array($this->get_plugin_name(), $instance->supported_purposes())) {
            // Currently selected instance does not support itt, so we do not add any options.
            return [];
        }

        return ['image' => PARAM_RAW, 'allowedmimetypes' => $this->get_allowed_mimetypes()];
    }

    /**
     * Returns an array of allowed mimetypes for files being submitted.
     *
     * @return array array of allowed mimetypes, for example ['image/jpg', 'image/png']
     * @throws coding_exception if the connector does not declare any allowed mimetypes
     */
    public function get_allowed_mimetypes(): array {
        global $USER;
        $userinfo = new userinfo($USER->id);
        $factory = \core\di::get(connector_factory::class);
        $connector = $factory->get_connector_by_purpose($this->get_plugin_name(), $userinfo->get_role());
        if (!method_exists($connector, 'allowed_mimetypes') || empty($connector->allowed_mimetypes())) {
            throw new coding_exception('Connector does not declare allowed mimetypes. Cannot be used for image to text');
        }
        return $connector->allowed_mimetypes();
    }
}

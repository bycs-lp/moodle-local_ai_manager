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
use moodle_exception;

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
     * Returns the raw unmodified output from the AI tool.
     *
     * The ITT output is consumed by various plugins (e.g. assignfeedback_aif,
     * qbank_questiongen) for different purposes: some forward it to another LLM,
     * some display it in the frontend. Only the consuming plugin knows whether
     * the output should be escaped, sanitized or displayed as-is.
     * Therefore the ITT purpose must not apply any transformation (no Markdown
     * to HTML conversion, no escaping, no tag stripping) and delegates the
     * responsibility for output handling to the consuming plugin.
     *
     * @param string $output the output/result from the API of the AI tool
     * @return string the unmodified output
     */
    #[\Override]
    public function format_output(string $output): string {
        return $output;
    }

    /**
     * Rejects an image option that is not a base64 data url of an allowed mimetype.
     *
     * PARAM_RAW is unavoidable for a multi-megabyte base64 payload, so the shape has to be checked here.
     *
     * @param array $options the submitted request options
     * @return array the unchanged options
     * @throws moodle_exception if the image option is not a data url of an allowed mimetype
     */
    #[\Override]
    public function get_additional_request_options(array $options): array {
        if (array_key_exists('image', $options)) {
            $commaposition = strpos($options['image'], ',');
            $header = $commaposition === false ? '' : substr($options['image'], 0, $commaposition);
            if (!str_starts_with($header, 'data:') || !str_ends_with($header, ';base64')) {
                throw new moodle_exception('exception_badmessageformat', 'local_ai_manager');
            }
            $mimetype = strtolower(substr($header, strlen('data:'), -strlen(';base64')));
            if (!in_array($mimetype, array_map('strtolower', $this->get_allowed_mimetypes()), true)) {
                throw new moodle_exception('exception_badmessageformat', 'local_ai_manager');
            }
        }
        return $options;
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

        try {
            $allowedmimetypes = $this->get_allowed_mimetypes();
        } catch (coding_exception | moodle_exception) {
            // If the connector/model is currently invalid for ITT, do not expose image upload options.
            return [];
        }

        return ['image' => PARAM_RAW, 'allowedmimetypes' => $allowedmimetypes];
    }

    /**
     * Returns an array of allowed mimetypes for files being submitted.
     *
     * @return array array of allowed mimetypes, for example ['image/jpg', 'image/png']
     * @throws coding_exception if the connector does not declare any allowed mimetypes
     * @throws moodle_exception if the underlying connector cannot resolve the currently configured model
     */
    public function get_allowed_mimetypes(): array {
        global $USER;
        $userinfo = new userinfo($USER->id);
        $factory = \core\di::get(connector_factory::class);
        $connector = $factory->get_connector_by_purpose($this->get_plugin_name(), $userinfo->get_role());
        $allowedmimetypes = $connector->allowed_mimetypes();
        if (empty($allowedmimetypes)) {
            throw new coding_exception('Connector does not declare allowed mimetypes. Cannot be used for image to text');
        }
        return $allowedmimetypes;
    }
}

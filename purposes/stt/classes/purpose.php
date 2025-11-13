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

namespace aipurpose_stt;

use coding_exception;
use local_ai_manager\base_purpose;
use local_ai_manager\local\connector_factory;
use local_ai_manager\local\userinfo;

/**
 * Purpose stt methods.
 *
 * @package    aipurpose_stt
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purpose extends base_purpose {

    /**
     * Get additional purpose options for STT.
     *
     * @return array Array of options with validation types
     */
    #[\Override]
    public function get_additional_purpose_options(): array {
        global $USER;

        $factory = \core\di::get(connector_factory::class);
        $userinfo = new userinfo($USER->id);
        $connector = $factory->get_connector_by_purpose(
            $this->get_plugin_name(),
            $userinfo->get_role()
        );

        $instance = $connector->get_instance();
        if (!in_array($this->get_plugin_name(), $instance->supported_purposes())) {
            // Currently selected instance does not support stt.
            return [];
        }

        // Base options for all STT implementations.
        $returnoptions = [
            'audiofile' => PARAM_RAW,           // Audio file data/path.
            'language' => PARAM_TEXT,            // Source language (ISO 639-1).
            'prompt' => PARAM_TEXT,              // Optional context prompt.
            'response_format' => PARAM_TEXT,     // text, json, srt, vtt.
            'temperature' => PARAM_FLOAT,        // Sampling temperature (0-1).
            'allowedmimetypes' => $this->get_allowed_mimetypes(),
        ];

        // Get connector-specific options.
        $connectoroptions = $connector->get_available_options();

        // Validate that connector doesn't override base options.
        $allowedconnectorkeys = [
            'languages' => [],           // Array of supported languages.
            'response_formats' => [],    // Array of supported formats.
            'max_file_size' => 0,        // Max file size in bytes.
            'timestamp_granularities' => [], // segment, word.
        ];

        foreach ($connectoroptions as $key => $value) {
            if (array_key_exists($key, $returnoptions)) {
                throw new coding_exception(
                    'Connector must not override base option: ' . $key
                );
            }
            if (!array_key_exists($key, $allowedconnectorkeys)) {
                throw new coding_exception(
                    'Unknown connector option: ' . $key
                );
            }
        }

        return $returnoptions + $connectoroptions;
    }

    /**
     * Get additional request options.
     *
     * @param array $options Current options
     * @return array Modified options
     */
    #[\Override]
    public function get_additional_request_options(array $options): array {
        // Set defaults.
        if (empty($options['response_format'])) {
            $options['response_format'] = 'text';
        }

        if (empty($options['temperature'])) {
            $options['temperature'] = 0.0; // Deterministic by default.
        }

        // Validate temperature range.
        if ($options['temperature'] < 0 || $options['temperature'] > 1) {
            throw new \invalid_parameter_exception(
                'Temperature must be between 0 and 1'
            );
        }

        return $options;
    }

    /**
     * Format output for STT results.
     *
     * @param string $output Raw output from API
     * @return string Formatted output
     */
    #[\Override]
    public function format_output(string $output): string {
        // STT returns plain text transcription.
        // Clean but preserve line breaks.
        return clean_param($output, PARAM_TEXT);
    }

    /**
     * Get allowed MIME types for audio files.
     *
     * @return array Array of allowed MIME types
     * @throws coding_exception If connector doesn't declare MIME types
     */
    public function get_allowed_mimetypes(): array {
        global $USER;

        $userinfo = new userinfo($USER->id);
        $factory = \core\di::get(connector_factory::class);
        $connector = $factory->get_connector_by_purpose(
            $this->get_plugin_name(),
            $userinfo->get_role()
        );

        if (!method_exists($connector, 'allowed_mimetypes')
            || empty($connector->allowed_mimetypes())) {
            throw new coding_exception(
                'Connector does not declare allowed mimetypes for STT'
            );
        }

        return $connector->allowed_mimetypes();
    }
}

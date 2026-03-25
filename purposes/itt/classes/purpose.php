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
use core_files\redactor\services\exifremover_service;
use Exception;
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

    #[\Override]
    public function get_additional_request_options(array $options): array {
        if (!empty($options['image'])) {
            $options['image'] = $this->strip_metadata_from_data_url($options['image']);
        }
        return $options;
    }

    /**
     * Strips metadata from a base64 encoded data URL (image or PDF).
     *
     * Uses Moodle's exifremover_service if available and enabled.
     *
     * @param string $dataurl The data URL containing the base64 encoded file content
     * @return string The data URL with metadata stripped, or original if stripping fails/not supported
     */
    private function strip_metadata_from_data_url(string $dataurl): string {
        // Parse the data URL to extract mimetype and content.
        if (!preg_match('/^data:([^;]+);base64,(.+)$/', $dataurl, $matches)) {
            // Not a valid data URL, return as-is.
            return $dataurl;
        }

        $mimetype = $matches[1];
        $base64content = $matches[2];
        $filecontent = base64_decode($base64content);

        if ($filecontent === false) {
            // Failed to decode, return original.
            return $dataurl;
        }

        // Try to strip metadata using the exifremover_service.
        $strippedcontent = $this->strip_metadata($mimetype, $filecontent);

        if ($strippedcontent === null) {
            // Stripping not supported or failed, return original.
            return $dataurl;
        }

        // Re-encode and return.
        return 'data:' . $mimetype . ';base64,' . base64_encode($strippedcontent);
    }

    /**
     * Strips metadata from file content using the exifremover_service.
     *
     * @param string $mimetype The MIME type of the file
     * @param string $filecontent The raw file content
     * @return string|null The file content with metadata stripped, or null if not supported
     */
    private function strip_metadata(string $mimetype, string $filecontent): ?string {
        // Check if exifremover_service class exists (Moodle 4.5+).
        if (!class_exists('\core_files\redactor\services\exifremover_service')) {
            return null;
        }

        $exifremover = new exifremover_service();

        // Check if the service supports this mimetype.
        if (!$exifremover->is_mimetype_supported($mimetype)) {
            return null;
        }

        try {
            return $exifremover->redact_file_by_content($mimetype, $filecontent);
        } catch (Exception $e) {
            // Log the error but don't fail the request.
            debugging('Failed to strip metadata from file: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }
}

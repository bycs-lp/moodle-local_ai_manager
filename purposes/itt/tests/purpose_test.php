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

use core_plugin_manager;
use local_ai_manager\base_purpose;
use local_ai_manager\local\config_manager;
use local_ai_manager\local\connector_factory;
use local_ai_manager\local\userinfo;

/**
 * Tests for itt purpose.
 *
 * @package   aipurpose_itt
 * @copyright 2025 ISB Bayern
 * @author    Philipp Memmel
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class purpose_test extends \advanced_testcase {
    /**
     * Makes sure that all connector plugins that declare themselves compatible with the itt purpose also define allowed mimetypes.
     *
     * @covers \aipurpose_itt\purpose::get_allowed_mimetypes
     * @covers \local_ai_manager\base_connector::allowed_mimetypes
     */
    public function test_get_allowed_mimetypes(): void {
        $this->resetAfterTest();
        $connectorfactory = \core\di::get(connector_factory::class);
        foreach (array_keys(core_plugin_manager::instance()->get_installed_plugins('aitool')) as $aitool) {
            $newconnector = $connectorfactory->get_connector_by_connectorname($aitool);
            if (!empty($newconnector->get_models_by_purpose()['itt'])) {
                // Some connectors rely on a really existing instance, so we create one.
                $newinstance = $connectorfactory->get_new_instance($aitool);
                $newinstance->set_name('Test instance');
                $newinstance->set_endpoint('https://example.com');
                $newinstance->store();

                $empty = true;
                // We check that the connector returns at least for one of the models a non-empty list of allowed mimetypes.
                foreach ($newconnector->get_models_by_purpose()['itt'] as $model) {
                    $newinstance->set_model($model);
                    $newinstance->store();
                    $configmanager = \core\di::get(config_manager::class);
                    $configmanager->set_config(
                        base_purpose::get_purpose_tool_config_key('itt', userinfo::ROLE_BASIC),
                        $newinstance->get_id()
                    );
                    $connector = $connectorfactory->get_connector_by_purpose('itt', userinfo::ROLE_BASIC);
                    if (!empty($connector->allowed_mimetypes())) {
                        $empty = false;
                        break;
                    }
                }
                $this->assertFalse($empty);
            }
        }
    }

    /**
     * Tests that metadata is stripped from a valid JPEG data URL.
     *
     * @covers \aipurpose_itt\purpose::get_additional_request_options
     */
    public function test_get_additional_request_options_strips_metadata_from_jpeg(): void {
        $this->resetAfterTest();

        // Skip if exifremover_service is not available.
        if (!class_exists('\core_files\redactor\services\exifremover_service')) {
            $this->markTestSkipped('exifremover_service not available in this Moodle version.');
        }

        // Get the test image with EXIF data.
        $sourcepath = self::get_fixture_path('core_files', 'redactor/dummy.jpg');
        $imagecontent = file_get_contents($sourcepath);
        $dataurl = 'data:image/jpeg;base64,' . base64_encode($imagecontent);

        // Verify the original image has EXIF data.
        $originalexif = @exif_read_data($sourcepath);
        $this->assertNotEmpty($originalexif);
        $this->assertArrayHasKey('GPSLatitude', $originalexif);

        // Create the purpose and process the options.
        $purpose = new purpose();
        $options = ['image' => $dataurl];
        $result = $purpose->get_additional_request_options($options);

        // Verify the image option was processed.
        $this->assertArrayHasKey('image', $result);
        $this->assertNotEmpty($result['image']);

        // Extract the processed image content.
        $this->assertMatchesRegularExpression('/^data:image\/jpeg;base64,/', $result['image']);
        $processedcontent = base64_decode(explode(',', $result['image'])[1]);

        // Write to temp file to check EXIF data.
        $tempfile = make_request_directory() . '/processed.jpg';
        file_put_contents($tempfile, $processedcontent);

        // Verify EXIF data has been stripped.
        $processedexif = @exif_read_data($tempfile);
        // GD removes all EXIF data including GPS.
        $this->assertFalse(isset($processedexif['GPSLatitude']));
    }

    /**
     * Tests that invalid data URLs are returned unchanged.
     *
     * @covers \aipurpose_itt\purpose::get_additional_request_options
     */
    public function test_get_additional_request_options_invalid_data_url(): void {
        $this->resetAfterTest();

        $purpose = new purpose();

        // Test with invalid data URL format.
        $invalidurl = 'not-a-valid-data-url';
        $options = ['image' => $invalidurl];
        $result = $purpose->get_additional_request_options($options);

        $this->assertEquals($invalidurl, $result['image']);
    }

    /**
     * Tests that empty image option is handled correctly.
     *
     * @covers \aipurpose_itt\purpose::get_additional_request_options
     */
    public function test_get_additional_request_options_empty_image(): void {
        $this->resetAfterTest();

        $purpose = new purpose();

        // Test with empty image.
        $options = ['image' => ''];
        $result = $purpose->get_additional_request_options($options);

        $this->assertEquals('', $result['image']);
    }

    /**
     * Tests that options without image are passed through unchanged.
     *
     * @covers \aipurpose_itt\purpose::get_additional_request_options
     */
    public function test_get_additional_request_options_no_image(): void {
        $this->resetAfterTest();

        $purpose = new purpose();

        // Test with no image option.
        $options = ['someother' => 'value'];
        $result = $purpose->get_additional_request_options($options);

        $this->assertEquals(['someother' => 'value'], $result);
    }

    /**
     * Tests that unsupported mimetypes are returned unchanged.
     *
     * @covers \aipurpose_itt\purpose::get_additional_request_options
     */
    public function test_get_additional_request_options_unsupported_mimetype(): void {
        $this->resetAfterTest();

        // Skip if exifremover_service is not available.
        if (!class_exists('\core_files\redactor\services\exifremover_service')) {
            $this->markTestSkipped('exifremover_service not available in this Moodle version.');
        }

        $purpose = new purpose();

        // Create a data URL with unsupported mimetype.
        $dataurl = 'data:application/octet-stream;base64,' . base64_encode('some binary data');
        $options = ['image' => $dataurl];
        $result = $purpose->get_additional_request_options($options);

        // Should return unchanged since mimetype is not supported.
        $this->assertEquals($dataurl, $result['image']);
    }

    /**
     * Tests that malformed base64 content is handled gracefully.
     *
     * @covers \aipurpose_itt\purpose::get_additional_request_options
     */
    public function test_get_additional_request_options_malformed_base64(): void {
        $this->resetAfterTest();

        $purpose = new purpose();

        // Create a data URL with invalid base64 content.
        $dataurl = 'data:image/jpeg;base64,!!!invalid-base64!!!';
        $options = ['image' => $dataurl];
        $result = $purpose->get_additional_request_options($options);

        // Should return unchanged since base64 decoding fails.
        $this->assertEquals($dataurl, $result['image']);
    }

    /**
     * Tests metadata stripping with PNG images (if supported).
     *
     * @covers \aipurpose_itt\purpose::get_additional_request_options
     */
    public function test_get_additional_request_options_png_image(): void {
        $this->resetAfterTest();

        // Skip if exifremover_service is not available.
        if (!class_exists('\core_files\redactor\services\exifremover_service')) {
            $this->markTestSkipped('exifremover_service not available in this Moodle version.');
        }

        $purpose = new purpose();

        // Create a simple PNG image.
        $img = imagecreatetruecolor(10, 10);
        ob_start();
        imagepng($img);
        $pngcontent = ob_get_clean();
        imagedestroy($img);

        $dataurl = 'data:image/png;base64,' . base64_encode($pngcontent);
        $options = ['image' => $dataurl];
        $result = $purpose->get_additional_request_options($options);

        // PNG is not supported by default GD exifremover, so it should return unchanged.
        $this->assertEquals($dataurl, $result['image']);
    }

    /**
     * Tests that the purpose correctly handles data URLs with different casing.
     *
     * @covers \aipurpose_itt\purpose::get_additional_request_options
     */
    public function test_get_additional_request_options_data_url_parsing(): void {
        $this->resetAfterTest();

        $purpose = new purpose();

        // Test with missing base64 marker.
        $invalidurl = 'data:image/jpeg,' . base64_encode('content');
        $options = ['image' => $invalidurl];
        $result = $purpose->get_additional_request_options($options);

        // Should return unchanged since format doesn't match expected pattern.
        $this->assertEquals($invalidurl, $result['image']);
    }
}

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

namespace aitool_openaistt;

use advanced_testcase;

/**
 * PHPUnit tests for connector class.
 *
 * @package    aitool_openaistt
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \aitool_openaistt\connector
 */
class connector_test extends advanced_testcase {

    /**
     * Test get_models_by_purpose returns whisper-1 for STT.
     */
    public function test_get_models_by_purpose(): void {
        $this->resetAfterTest();

        $connector = new connector();
        $models = $connector->get_models_by_purpose();

        $this->assertIsArray($models);
        $this->assertArrayHasKey('stt', $models);
        $this->assertIsArray($models['stt']);
        $this->assertContains('whisper-1', $models['stt']);
    }

    /**
     * Test allowed_mimetypes returns correct audio formats.
     */
    public function test_allowed_mimetypes(): void {
        $this->resetAfterTest();

        $connector = new connector();
        $mimetypes = $connector->allowed_mimetypes();

        $this->assertIsArray($mimetypes);
        $this->assertCount(8, $mimetypes);
        $this->assertContains('audio/mpeg', $mimetypes);
        $this->assertContains('audio/mp3', $mimetypes);
        $this->assertContains('audio/wav', $mimetypes);
        $this->assertContains('audio/x-m4a', $mimetypes);
        $this->assertContains('audio/webm', $mimetypes);
        $this->assertContains('video/mp4', $mimetypes);
        $this->assertContains('video/mpeg', $mimetypes);
        $this->assertContains('audio/mpga', $mimetypes);
    }

    /**
     * Test get_supported_languages returns language array.
     */
    public function test_get_supported_languages(): void {
        $this->resetAfterTest();

        $connector = new connector();
        $languages = $connector->get_supported_languages();

        $this->assertIsArray($languages);
        $this->assertNotEmpty($languages);
        $this->assertArrayHasKey('key', $languages[0]);
        $this->assertArrayHasKey('displayname', $languages[0]);

        // Check some specific languages.
        $langkeys = array_column($languages, 'key');
        $this->assertContains('en', $langkeys);
        $this->assertContains('de', $langkeys);
        $this->assertContains('es', $langkeys);
    }

    /**
     * Test get_prompt_data validates file size correctly.
     */
    public function test_get_prompt_data_file_size_validation(): void {
        $this->resetAfterTest();

        $connector = new connector();

        // Create a mock stored_file that exceeds size limit.
        $filerecord = [
            'contextid' => 1,
            'component' => 'phpunit',
            'filearea' => 'test',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'test.mp3',
        ];

        $fs = get_file_storage();
        $file = $fs->create_file_from_string($filerecord, str_repeat('a', connector::MAX_FILE_SIZE + 1));

        $requestoptions = [
            'model' => 'whisper-1',
            'audiofile' => $file,
            'language' => 'en',
        ];

        $this->expectException(\invalid_parameter_exception::class);
        $this->expectExceptionMessage('filesizeexceeded');
        $connector->get_prompt_data('stt', $requestoptions);

        // Cleanup.
        $file->delete();
    }

    /**
     * Test get_prompt_data builds correct multipart data.
     */
    public function test_get_prompt_data_builds_multipart(): void {
        $this->resetAfterTest();

        $connector = new connector();

        // Create a valid mock file.
        $filerecord = [
            'contextid' => 1,
            'component' => 'phpunit',
            'filearea' => 'test',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'test.mp3',
        ];

        $fs = get_file_storage();
        $file = $fs->create_file_from_string($filerecord, 'dummy audio content');

        $requestoptions = [
            'model' => 'whisper-1',
            'audiofile' => $file,
            'language' => 'de',
            'prompt' => 'Test prompt',
            'response_format' => 'json',
            'temperature' => 0.5,
        ];

        $result = $connector->get_prompt_data('stt', $requestoptions);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('multipart', $result);
        $this->assertIsArray($result['multipart']);

        // Check multipart structure.
        $parts = $result['multipart'];
        $this->assertGreaterThan(0, count($parts));

        // Verify required fields are present.
        $names = array_column($parts, 'name');
        $this->assertContains('file', $names);
        $this->assertContains('model', $names);
        $this->assertContains('language', $names);
        $this->assertContains('prompt', $names);
        $this->assertContains('response_format', $names);
        $this->assertContains('temperature', $names);

        // Cleanup.
        $file->delete();
    }

    /**
     * Test execute_prompt_completion handles text response.
     */
    public function test_execute_prompt_completion_text_format(): void {
        $this->resetAfterTest();

        $connector = new connector();

        // Mock response stream.
        $mockstream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $mockstream->method('getContents')->willReturn('This is a test transcription.');

        $responsebody = $mockstream;
        $code = 200;
        $requestoptions = ['response_format' => 'text'];

        $result = $connector->execute_prompt_completion($responsebody, $code, $requestoptions);

        $this->assertArrayHasKey('code', $result);
        $this->assertEquals(200, $result['code']);
        $this->assertArrayHasKey('response', $result);
        $this->assertEquals('This is a test transcription.', $result['response']->get_content());
    }

    /**
     * Test execute_prompt_completion handles JSON response.
     */
    public function test_execute_prompt_completion_json_format(): void {
        $this->resetAfterTest();

        $connector = new connector();

        // Mock JSON response.
        $jsonresponse = json_encode(['text' => 'This is a JSON transcription.']);
        $mockstream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $mockstream->method('getContents')->willReturn($jsonresponse);

        $responsebody = $mockstream;
        $code = 200;
        $requestoptions = ['response_format' => 'json'];

        $result = $connector->execute_prompt_completion($responsebody, $code, $requestoptions);

        $this->assertArrayHasKey('code', $result);
        $this->assertEquals(200, $result['code']);
        $this->assertArrayHasKey('response', $result);
        $this->assertEquals('This is a JSON transcription.', $result['response']->get_content());
    }

    /**
     * Test execute_prompt_completion handles error codes.
     */
    public function test_execute_prompt_completion_error_handling(): void {
        $this->resetAfterTest();

        $connector = new connector();

        // Mock error response.
        $mockstream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $mockstream->method('getContents')->willReturn('Error message');

        $responsebody = $mockstream;
        $code = 401;
        $requestoptions = ['response_format' => 'text'];

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('http401');
        $connector->execute_prompt_completion($responsebody, $code, $requestoptions);
    }
}

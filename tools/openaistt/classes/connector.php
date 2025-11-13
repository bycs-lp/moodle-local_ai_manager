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

use local_ai_manager\base_purpose;
use local_ai_manager\local\prompt_response;
use local_ai_manager\local\unit;
use local_ai_manager\local\usage;
use local_ai_manager\request_options;
use Psr\Http\Message\StreamInterface;

/**
 * Connector for OpenAI Speech to Text (Whisper).
 *
 * @package    aitool_openaistt
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class connector extends \local_ai_manager\base_connector {

    /** @var int Maximum file size in bytes (25 MB) */
    const MAX_FILE_SIZE = 26214400;

    #[\Override]
    public function get_models_by_purpose(): array {
        $modelsbypurpose = base_purpose::get_installed_purposes_array();
        $modelsbypurpose['stt'] = ['whisper-1'];
        return $modelsbypurpose;
    }

    #[\Override]
    public function get_prompt_data(string $prompttext, request_options $requestoptions): array {
        $options = $requestoptions->get_options();

        // Validate file size.
        if (!empty($options['audiofile']) && strlen($options['audiofile']) > self::MAX_FILE_SIZE) {
            throw new \moodle_exception('error_filesizeexceeded', 'aitool_openaistt', '',
                ['max' => self::MAX_FILE_SIZE, 'actual' => strlen($options['audiofile'])]);
        }

        // Build multipart form data.
        $data = [
            'file' => $options['audiofile'],
            'model' => $this->instance->get_model(),
        ];

        // Optional parameters.
        if (!empty($options['language'])) {
            $data['language'] = $options['language'];
        }

        if (!empty($options['prompt'])) {
            $data['prompt'] = $options['prompt'];
        }

        if (isset($options['response_format'])) {
            $data['response_format'] = $options['response_format'];
        }

        if (isset($options['temperature'])) {
            $data['temperature'] = $options['temperature'];
        }

        if (!empty($options['timestamp_granularities'])) {
            $data['timestamp_granularities'] = $options['timestamp_granularities'];
        }

        return $data;
    }

    #[\Override]
    protected function get_headers(): array {
        $headers = [
            'Authorization' => 'Bearer ' . $this->instance->get_apikey(),
        ];
        return $headers;
    }

    #[\Override]
    public function get_unit(): unit {
        return unit::COUNT;
    }

    #[\Override]
    public function execute_prompt_completion(StreamInterface $result, request_options $requestoptions): prompt_response {
        $options = $requestoptions->get_options();
        $responseformat = $options['response_format'] ?? 'text';

        $resultcontent = $result->getContents();

        // Handle different response formats.
        switch ($responseformat) {
            case 'json':
            case 'verbose_json':
                $jsonresult = json_decode($resultcontent, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \moodle_exception('error_invalidjson', 'aitool_openaistt');
                }
                $transcription = $jsonresult['text'] ?? '';
                break;

            case 'text':
            case 'srt':
            case 'vtt':
            default:
                $transcription = $resultcontent;
                break;
        }

        // Usage is counted as 1 request (OpenAI doesn't provide token counts for Whisper).
        return prompt_response::create_from_result(
            $this->instance->get_model(),
            new usage(1.0),
            $transcription
        );
    }

    #[\Override]
    public function get_available_options(): array {
        $options = [
            'languages' => $this->get_supported_languages(),
            'response_formats' => ['text', 'json', 'verbose_json', 'srt', 'vtt'],
            'max_file_size' => self::MAX_FILE_SIZE,
            'timestamp_granularities' => ['segment', 'word'],
        ];

        return $options;
    }

    /**
     * Returns allowed MIME types for audio files.
     *
     * @return array Array of allowed MIME types
     */
    public function allowed_mimetypes(): array {
        return [
            'audio/mpeg',       // MP3.
            'audio/mp4',        // MP4.
            'audio/x-m4a',      // M4A.
            'audio/wav',        // WAV.
            'audio/webm',       // WEBM.
            'video/mp4',        // MP4 (video with audio track).
            'video/mpeg',       // MPEG.
            'audio/mpga',       // MPGA.
        ];
    }

    /**
     * Returns list of supported languages.
     *
     * @return array Array of language codes with display names
     */
    private function get_supported_languages(): array {
        return [
            ['key' => 'af', 'displayname' => 'Afrikaans'],
            ['key' => 'ar', 'displayname' => 'Arabic'],
            ['key' => 'hy', 'displayname' => 'Armenian'],
            ['key' => 'az', 'displayname' => 'Azerbaijani'],
            ['key' => 'be', 'displayname' => 'Belarusian'],
            ['key' => 'bs', 'displayname' => 'Bosnian'],
            ['key' => 'bg', 'displayname' => 'Bulgarian'],
            ['key' => 'ca', 'displayname' => 'Catalan'],
            ['key' => 'zh', 'displayname' => 'Chinese'],
            ['key' => 'hr', 'displayname' => 'Croatian'],
            ['key' => 'cs', 'displayname' => 'Czech'],
            ['key' => 'da', 'displayname' => 'Danish'],
            ['key' => 'nl', 'displayname' => 'Dutch'],
            ['key' => 'en', 'displayname' => 'English'],
            ['key' => 'et', 'displayname' => 'Estonian'],
            ['key' => 'fi', 'displayname' => 'Finnish'],
            ['key' => 'fr', 'displayname' => 'French'],
            ['key' => 'gl', 'displayname' => 'Galician'],
            ['key' => 'de', 'displayname' => 'German'],
            ['key' => 'el', 'displayname' => 'Greek'],
            ['key' => 'he', 'displayname' => 'Hebrew'],
            ['key' => 'hi', 'displayname' => 'Hindi'],
            ['key' => 'hu', 'displayname' => 'Hungarian'],
            ['key' => 'is', 'displayname' => 'Icelandic'],
            ['key' => 'id', 'displayname' => 'Indonesian'],
            ['key' => 'it', 'displayname' => 'Italian'],
            ['key' => 'ja', 'displayname' => 'Japanese'],
            ['key' => 'kn', 'displayname' => 'Kannada'],
            ['key' => 'kk', 'displayname' => 'Kazakh'],
            ['key' => 'ko', 'displayname' => 'Korean'],
            ['key' => 'lv', 'displayname' => 'Latvian'],
            ['key' => 'lt', 'displayname' => 'Lithuanian'],
            ['key' => 'mk', 'displayname' => 'Macedonian'],
            ['key' => 'ms', 'displayname' => 'Malay'],
            ['key' => 'mr', 'displayname' => 'Marathi'],
            ['key' => 'mi', 'displayname' => 'Maori'],
            ['key' => 'ne', 'displayname' => 'Nepali'],
            ['key' => 'no', 'displayname' => 'Norwegian'],
            ['key' => 'fa', 'displayname' => 'Persian'],
            ['key' => 'pl', 'displayname' => 'Polish'],
            ['key' => 'pt', 'displayname' => 'Portuguese'],
            ['key' => 'ro', 'displayname' => 'Romanian'],
            ['key' => 'ru', 'displayname' => 'Russian'],
            ['key' => 'sr', 'displayname' => 'Serbian'],
            ['key' => 'sk', 'displayname' => 'Slovak'],
            ['key' => 'sl', 'displayname' => 'Slovenian'],
            ['key' => 'es', 'displayname' => 'Spanish'],
            ['key' => 'sw', 'displayname' => 'Swahili'],
            ['key' => 'sv', 'displayname' => 'Swedish'],
            ['key' => 'tl', 'displayname' => 'Tagalog'],
            ['key' => 'ta', 'displayname' => 'Tamil'],
            ['key' => 'th', 'displayname' => 'Thai'],
            ['key' => 'tr', 'displayname' => 'Turkish'],
            ['key' => 'uk', 'displayname' => 'Ukrainian'],
            ['key' => 'ur', 'displayname' => 'Urdu'],
            ['key' => 'vi', 'displayname' => 'Vietnamese'],
            ['key' => 'cy', 'displayname' => 'Welsh'],
        ];
    }
}

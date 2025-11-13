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
 * Lang strings for aitool_openaistt - EN.
 *
 * @package    aitool_openaistt
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['adddescription'] = 'Whisper is a speech-to-text model developed by OpenAI that supports multilingual transcription and translation.';
$string['pluginname'] = 'OpenAI Whisper';
$string['privacy:metadata'] = 'The local ai_manager tool subplugin "OpenAI Whisper" does not store any personal data.';
$string['privacy:metadata:audiofile'] = 'Audio file is sent to OpenAI Whisper API for transcription.';
$string['privacy:metadata:language'] = 'Source language code is sent to improve transcription accuracy.';
$string['privacy:metadata:prompt'] = 'Optional context prompt is sent to improve transcription quality.';
$string['privacy:metadata:openai_whisper_api'] = 'Audio data is processed by OpenAI Whisper API to generate text transcriptions.';
$string['error_filesizeexceeded'] = 'File size exceeds maximum allowed size. Max: {$a->max} bytes, Actual: {$a->actual} bytes.';
$string['error_invalidjson'] = 'Invalid JSON response from OpenAI Whisper API.';
$string['error_http400'] = 'Bad Request: {$a}';
$string['error_http401'] = 'Unauthorized: Invalid API key.';
$string['error_http413'] = 'Payload Too Large: File size exceeds OpenAI limit.';
$string['error_ratelimit'] = 'Rate limit exceeded. Please try again later.';
$string['error_http500'] = 'OpenAI server error. Please try again later.';
$string['error_unknown'] = 'Unknown error: {$a}';

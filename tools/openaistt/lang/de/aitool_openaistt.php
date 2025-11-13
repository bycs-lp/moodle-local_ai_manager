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
 * Lang strings for aitool_openaistt - DE.
 *
 * @package    aitool_openaistt
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['adddescription'] = 'Whisper ist ein Sprach-zu-Text-Modell von OpenAI, das mehrsprachige Transkription und Übersetzung unterstützt.';
$string['pluginname'] = 'OpenAI Whisper';
$string['privacy:metadata'] = 'Das local ai_manager Tool-Subplugin "OpenAI Whisper" speichert keine personenbezogenen Daten.';
$string['privacy:metadata:audiofile'] = 'Audiodatei wird zur Transkription an die OpenAI Whisper API gesendet.';
$string['privacy:metadata:language'] = 'Quellsprachen-Code wird gesendet, um die Transkriptionsgenauigkeit zu verbessern.';
$string['privacy:metadata:prompt'] = 'Optionaler Kontext-Prompt wird gesendet, um die Transkriptionsqualität zu verbessern.';
$string['privacy:metadata:openai_whisper_api'] = 'Audiodaten werden von der OpenAI Whisper API verarbeitet, um Texttranskriptionen zu erzeugen.';
$string['error_filesizeexceeded'] = 'Dateigröße überschreitet die maximal erlaubte Größe. Max: {$a->max} Bytes, Tatsächlich: {$a->actual} Bytes.';
$string['error_invalidjson'] = 'Ungültige JSON-Antwort von OpenAI Whisper API.';
$string['error_http400'] = 'Fehlerhafte Anfrage: {$a}';
$string['error_http401'] = 'Nicht autorisiert: Ungültiger API-Schlüssel.';
$string['error_http413'] = 'Datenmenge zu groß: Dateigröße überschreitet OpenAI-Limit.';
$string['error_ratelimit'] = 'Ratenlimit überschritten. Bitte versuchen Sie es später erneut.';
$string['error_http500'] = 'OpenAI-Serverfehler. Bitte versuchen Sie es später erneut.';
$string['error_unknown'] = 'Unbekannter Fehler: {$a}';

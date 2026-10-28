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
 * Purpose genai methods
 *
 * @package    aipurpose_questiongeneration
 * @copyright  ISB Bayern, 2024
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aipurpose_questiongeneration;

use local_ai_manager\base_purpose;
use local_ai_manager\local\requestoptions\conversationcontext;
use local_ai_manager\request_options;
use Locale;

/**
 * Purpose genai methods
 *
 * @package    aipurpose_questiongeneration
 * @copyright  ISB Bayern, 2024
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purpose extends base_purpose {
    #[\Override]
    public function get_additional_purpose_options(): array {
        return \core\di::get(conversationcontext::class)->get_option_definition();
    }

    #[\Override]
    public function validate_purpose_options(array $options): void {
        if (array_key_exists(conversationcontext::OPTION_NAME, $options)) {
            \core\di::get(conversationcontext::class)->validate($options[conversationcontext::OPTION_NAME]);
        }
    }

    #[\Override]
    public function format_output(string $output): string {
        // If the LLM returns a code block, remove the Markdown wrapper (```) and additional text
        // around the result.
        $output = trim($output);
        $matches = [];
        $triplebackticks = "\u{0060}\u{0060}\u{0060}";
        preg_match('/' . $triplebackticks . '[a-zA-Z0-9]*\s*(.*?)\s*' . $triplebackticks . '/s', $output, $matches);
        if (count($matches) > 1) {
            $output = trim($matches[1]);
        }
        // Some LLMs return UTF8-Symbols as UNICODE code. We have to convert this to proper UTF-8 symbols.
        $output = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($matches) {
            return mb_convert_encoding(pack('H*', $matches[1]), 'UTF-8', 'UCS-2BE');
        }, $output);

        // Clean the HTML inside the text nodes of Moodle XML, so the XML stays importable but carries no active content.
        // The importer would expand entities of a DOCTYPE, so such output is dropped.
        if (stripos($output, '<!DOCTYPE') !== false) {
            return '';
        }
        try {
            $xml = new \SimpleXMLElement($output, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_PARSEHUGE);
        } catch (\Exception $e) {
            // Not parseable as XML; leave other formats to the consuming plugin.
            return $output;
        }
        $changed = false;
        // Match by local name, so a namespace declared by the LLM output does not hide the nodes from the sanitising.
        foreach ($xml->xpath('//*[local-name()="text"]') as $node) {
            $parents = $node->xpath('..');
            $parent = $parents[0];
            $format = trim((string) $parent['format']);
            if ($format === 'plain_text') {
                continue;
            }
            $text = (string) $node;
            if ($format === 'markdown') {
                // The importer would keep javascript: links of markdown, so store the cleaned HTML instead.
                $text = markdown_to_html($text);
                $parent['format'] = 'html';
                $changed = true;
            }
            $cleaned = clean_text($text, FORMAT_HTML);
            if ($cleaned !== (string) $node) {
                $node[0] = $cleaned;
                $changed = true;
            }
        }
        return $changed ? $xml->asXML() : $output;
    }
}

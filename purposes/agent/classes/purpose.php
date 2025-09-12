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
 * Purpose chat methods
 *
 * @package    aipurpose_agent
 * @copyright  ISB Bayern, 2024
 * @author     Andreas Wagner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aipurpose_agent;

use local_ai_manager\base_purpose;
use local_ai_manager\request_options;

/**
 * Purpose AI-Agent
 *
 * @package    aipurpose_agent
 * @copyright  ISB Bayern, 2024
 * @author     Andreas Wagner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purpose extends base_purpose {

    /** @var array @var array keep the rawoptions during processing */
    protected $sanitizedoptions = [];

    #[\Override]
    public function get_additional_request_options(array $options): array {
        return $options;
    }

    #[\Override]
    public function get_additional_purpose_options(): array {
        return ['agentoptions' => base_purpose::PARAM_ARRAY];
    }

    #[\Override]
    public function format_prompt_text(string $prompttext, request_options $requestoptions): string {
        global $CFG, $PAGE;

        // Keep the options for validating the ai answer
        $this->sanitizedoptions = $requestoptions->get_options();

        // If $sanitizedoptions contains domelements add genericprompt and add domelements.
        if (!isset($this->sanitizedoptions['agentoptions']['formelements'])) {
            return $prompttext;
        }

        // Build the prompt. Start with generic prompt.
        $genericprompt = file_get_contents($CFG->dirroot . '/local/ai_manager/purposes/agent/assets/genericprompt.txt');

        // Add formelement options.
        $formelementoptionsjson = json_encode(['formelements' => $this->sanitizedoptions['agentoptions']['formelements']]);
        $formattedprompt = str_replace('{{formelementsjson}}', $formelementoptionsjson, $genericprompt);

        // Process pagedock URLs and extract documentation content
        $docs = '';
        if (isset($this->sanitizedoptions['agentoptions']['pagedock']) &&
            is_array($this->sanitizedoptions['agentoptions']['pagedock'])) {

            foreach ($this->sanitizedoptions['agentoptions']['pagedock'] as $doclink) {
                if (isset($doclink['url'])) {
                    $content = $this->fetch_documentation_content($doclink['url']);
                    if (!empty($content)) {
                        $context = isset($doclink['context']) ? $doclink['context'] : 'Documentation';
                        $docs .= "\n\n=== {$context} ===\n" . $content . "\n";
                    }
                }
            }
        }

        // Add documentation content to prompt
        $formattedprompt = str_replace('{{docs}}', $docs, $formattedprompt);

        // TODO: Add the moodle doc pages or information from other sources.
        $docpagelink = page_get_doc_link_path($PAGE);

        // TODO: make the next line usable for other modtypes than assignment.
        if (!empty($this->sanitizedoptions['agentoptions']['pageid'])) {
            $formattedprompt = str_replace('{{pageid}}', $this->sanitizedoptions['agentoptions']['pageid'], $formattedprompt);
        }

        // Replace the teacherinput.
        $formattedprompt = str_replace('{{teacherinput}}', $prompttext, $formattedprompt);

        return $formattedprompt;
    }

    /**
     * Fetch documentation content from URL using cURL
     *
     * @param string $url The URL to fetch content from
     * @return string The extracted content or empty string on failure
     */
    protected function fetch_documentation_content(string $url): string {
        // Initialize cURL
        $ch = curl_init();

        // Set cURL options
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'Moodle AI Agent Bot 1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        // Execute cURL request
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Check if request was successful
        if ($html === false || $httpCode !== 200) {
            return '';
        }

        // Parse the HTML and extract content
        return $this->extract_content_from_html($html, $url);
    }

    /**
     * Extract content from HTML based on the domain
     *
     * @param string $html The HTML content
     * @param string $url The original URL for domain checking
     * @return string The extracted content
     */
    protected function extract_content_from_html(string $html, string $url): string {
        // Create DOMDocument to parse HTML
        $dom = new \DOMDocument();

        // Suppress warnings for malformed HTML
        libxml_use_internal_errors(true);
        $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $content = '';

        // Check if URL is from docs.moodle.org
        if (strpos($url, 'docs.moodle.org') !== false) {
            // Try to find bodyContent div for Moodle documentation
            $xpath = new \DOMXPath($dom);
            $bodyContentNodes = $xpath->query('//div[@id="bodyContent"]');

            if ($bodyContentNodes->length > 0) {
                $content = $this->get_text_content($bodyContentNodes->item(0));
            } else {
                // Fallback: try other common content selectors
                $selectors = ['#content', '.mw-parser-output', 'main', 'article', 'body'];
                foreach ($selectors as $selector) {
                    $nodes = $xpath->query('//*[@id="' . substr($selector, 1) . '"]');
                    if ($nodes->length > 0) {
                        $content = $this->get_text_content($nodes->item(0));
                        break;
                    }
                }
            }
        } else {
            // For other domains, return the entire DOM as string
            $content = $dom->textContent;
        }

        // Clean up the content
        $content = preg_replace('/\s+/', ' ', $content); // Replace multiple whitespace with single space
        $content = trim($content);

        // Limit content length to prevent prompt from becoming too large
        if (strlen($content) > 5000) {
            $content = substr($content, 0, 5000) . '...';
        }

        return $content;
    }

    /**
     * Extract text content from a DOM node, excluding script and style elements
     *
     * @param \DOMNode $node The DOM node to extract text from
     * @return string The extracted text content
     */
    protected function get_text_content(\DOMNode $node): string {
        $xpath = new \DOMXPath($node->ownerDocument);

        // Remove script and style elements
        $elementsToRemove = $xpath->query('.//script | .//style | .//nav | .//footer', $node);
        foreach ($elementsToRemove as $element) {
            if ($element->parentNode) {
                $element->parentNode->removeChild($element);
            }
        }

        return $node->textContent ?? '';
    }

    /**
     * Check formelements contained in the ai response and remove them if id was not present in the prompt.
     *
     * @param array $formelementsfromai
     * @return array
     */
    protected function validate_formelements(array $formelementsfromai): array {

        // Gather the valid formelements.
        $validformelementids = [];
        foreach ($this->sanitizedoptions['agentoptions']['formelements'] as $formelement) {
            if (isset($formelement['id'])) {
                $validformelementids[$formelement['id']] = $formelement['id'];
            }
        }

        // Filter formelements from the ai response by checking id.
        $filteredformelements = [];
        foreach ($formelementsfromai as $formelement) {

            if (isset($validformelementids[$formelement['id']])) {
                $filteredformelements[] = $formelement;
            }
        }

        return $filteredformelements;
    }

    /**
     * Validates and structures the given chat output data by formatting it into an associative array.
     *
     * @param array $chatoutput An array of chat output data where each element is expected to have 'type' and 'text' keys.
     * @return array An array of structured chat output data containing 'intro' and 'outro' types along with their corresponding texts.
     */
    protected function validate_chatoutput(array $chatoutput): array {

        // Convert into assoziative array;
        $outputrecord = [];
        foreach ($chatoutput as $value) {
            if (!isset($value['type']) || !isset($value['text'])) {
                continue;
            }
            $outputrecord[$value['type']] = $value['text'];
        }
        return [
                [
                        'type' => 'intro',
                        'text' => $outputrecord['intro'] ?? '',
                ],
                [
                        'type' => 'outro',
                        'text' => $outputrecord['outro'] ?? '',
                ],
        ];
    }

    #[\Override]
    public function format_output(string $output): string {
        // Standard data to return, when validation fails.
        $erroroutput = json_encode([
                'formelements' => [],
                'chatoutput' => [
                        [
                                'type' => 'intro',
                                'text' => get_string('errorresponse', 'aipurpose_agent')
                        ],
                        [
                                'type' => 'outro',
                                'text' => ''
                        ],
                ]
        ]);

        // Do a basic validation here.
        $output = trim($output);

        // Clean the ai response (should be pure json object).
        $matches = [];
        $triplebackticks = "\u{0060}\u{0060}\u{0060}";
        preg_match('/' . $triplebackticks . '[a-zA-Z0-9]*\s*(.*?)\s*' . $triplebackticks . '/s', $output, $matches);
        if (count($matches) > 1) {
            $output = trim($matches[1]);
        }

        $outputrecord = json_decode($output, true);

        if (empty($outputrecord)) {
            return $erroroutput;
        }

        // Checking the formelements in the response.
        $outputrecord['formelements'] = $this->validate_formelements($outputrecord['formelements']);

        if (!isset($outputrecord['formelements'])) {
            return $erroroutput;
        }

        if (!isset($outputrecord['chatoutput'])) {
            return $erroroutput;
        }

        // Checking the correct structure of chat output.
        $outputrecord['chatoutput'] = $this->validate_chatoutput($outputrecord['chatoutput']);

        // TODO: do a validation based on sanitized options.

        return json_encode($outputrecord);
    }
}

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
        $formatedprompt = str_replace('[formelementsjson]', $formelementoptionsjson, $genericprompt);

        // TODO: Add the moodle doc pages or information from other sources.
        $docpagelink = page_get_doc_link_path($PAGE);

        // TODO: make the next line usable for other modtypes than assignment.
        $formatedprompt = str_replace('[modtype]', 'assignment', $formatedprompt);

        // Replace the teacherinput.
        $formatedprompt = str_replace('[teacherinput]', $prompttext, $formatedprompt);

        return $formatedprompt;
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
                                'text' => 'Sorry, I am not able to assist you.'
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

        if (!isset($outputrecord['formelements'])) {
            return $erroroutput;
        }

        if (!isset($outputrecord['chatoutput'])) {
            return $erroroutput;
        }

        // Checking the correct structure of chat output.
        $outputrecord['chatoutput'] = $this->validate_chatoutput($outputrecord['chatoutput']);

        // TODO: do a validation based on sanitized options.

        return $output;
    }
}

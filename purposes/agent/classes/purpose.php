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

    /**
     * @var array Storage variable to keep the raw options sent from the frontend.
     *
     * Before doing the AI request the options from the frontend will be stored. After the AI request has been made they
     * are used to sanitize the AI output.
     */
    private array $storedoptions = [];

    #[\Override]
    public function get_additional_request_options(array $options): array {
        global $CFG, $PAGE;

        // Keep the options for validating the AI answer.
        $this->storedoptions = $options;

        if (!isset($this->storedoptions['agentoptions']['formelements'])) {
            return [];
        }

        // Build the prompt. Start with generic prompt.
        $genericprompt = file_get_contents($CFG->dirroot . '/local/ai_manager/purposes/agent/assets/genericprompt.txt');

        // Add formelement options.
        $formelementoptionsjson = json_encode(['formelements' => $this->storedoptions['agentoptions']['formelements']]);
        $formattedprompt = str_replace('{{formelementsjson}}', $formelementoptionsjson, $genericprompt);

        // TODO: Add the moodle doc pages or information from other sources.
        $docpagelink = page_get_doc_link_path($PAGE);

        if (!empty($this->storedoptions['agentoptions']['pageid'])) {
            $formattedprompt = str_replace('{{pageid}}', $this->storedoptions['agentoptions']['pageid'], $formattedprompt);
        }

        $currentconversationcontext = $options['conversationcontext'] ?? [];

        return [
            'conversationcontext' => [
                ...$currentconversationcontext,
                [
                    'sender' => 'user',
                    'message' => $formattedprompt,
                ],
            ],
        ];
    }

    #[\Override]
    public function get_additional_purpose_options(): array {
        return ['conversationcontext' => base_purpose::PARAM_ARRAY, 'agentoptions' => base_purpose::PARAM_ARRAY];
    }

    #[\Override]
    public function format_prompt_text(string $prompttext, request_options $requestoptions): string {
        return $prompttext;
    }

    /**
     * Check formelements contained in the AI response and remove them if id was not present in the prompt.
     *
     * @param array $formelementsfromai the form elements returned from the AI
     * @return array the validated/sanitized input array
     */
    protected function validate_formelements(array $formelementsfromai): array {
        // We only validate if the stored options are available.
        // This is only the case if we are in the thread that actually queries the external AI system.
        // Sanitizing however is not necessary when we just format a stored response.
        if (empty($this->storedoptions)) {
            return $formelementsfromai;
        }
        $validformelementids = [];
        foreach ($this->storedoptions['agentoptions']['formelements'] as $formelement) {
            if (isset($formelement['id'])) {
                $validformelementids[$formelement['id']] = $formelement['id'];
            }
        }

        // Filter formelements from the AI response by checking id.
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
     * @return array An array of structured chat output data containing 'intro' and 'outro' types along with their corresponding
     *     texts.
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

        $output = trim($output);

        // Clean the AI response (should be pure JSON object).
        // First of all, remove triple backticks and language specifier if present. Some models will keep formatting code like this
        // despite being instructed to only return plain JSON.
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
        foreach ($outputrecord['chatoutput'] as $key => $outputobject) {
            $outputrecord['chatoutput'][$key]['text'] = format_text($outputobject['text'], FORMAT_MARKDOWN, ['filter' => false]);
        }

        return json_encode($outputrecord);
    }
}

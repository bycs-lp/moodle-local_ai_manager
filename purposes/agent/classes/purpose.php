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
use Locale;

/**
 * Purpose AI-Agent
 *
 * @package    aipurpose_agent
 * @copyright  2025 ISB Bayern
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
        global $CFG, $DB;
        require_once($CFG->libdir . '/blocklib.php');

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
        $formattedprompt = str_replace(
            '{{currentlang}}',
            Locale::getDisplayLanguage(current_language(), 'en'),
            $formattedprompt
        );
        $formattedprompt = str_replace('{{pageid}}', $this->storedoptions['agentoptions']['pageid'], $formattedprompt);

        $currentconversationcontext = $options['conversationcontext'] ?? [];
        $conversationcontext = $currentconversationcontext;

        if (!empty($this->storedoptions['agentoptions']['additionalcontext'])) {
            $additionalcontextstext = $this->storedoptions['agentoptions']['additionalcontext'];
            $additionalcontextstext =
                'Here is some additional context for the assignment the next prompt will give you:'
                . PHP_EOL . PHP_EOL
                . $additionalcontextstext;
            $conversationcontext[] = [
                'sender' => 'user',
                'message' => $additionalcontextstext,
            ];
        }

        $conversationcontext[] = [
            'sender' => 'user',
            'message' => $formattedprompt,
        ];

        return ['conversationcontext' => $conversationcontext];
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
        // Convert into associative array.
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
                    'text' => get_string('error_unusuableresponse', 'aipurpose_agent'),
                ],
                [
                    'type' => 'outro',
                    'text' => '',
                ],
            ],
        ]);

        // Clean the AI response (should be pure JSON object).
        $output = trim($output);
        $outputrecord = $this->extract_single_json_object($output);

        // The AI is instructed to always return a JSON object, even if no suggestions are included.
        if (empty($outputrecord)) {
            return json_encode([
                'formelements' => [],
                'chatoutput' => [
                    [
                        'type' => 'intro',
                        'text' => $outputrecord,
                    ],
                    [
                        'type' => 'outro',
                        'text' => '',
                    ],
                ],
            ]);
        }

        // Checking the formelements in the response.
        if (!empty($formelements)) {
            // We only do this if we have non-empty formelements. AI Instructions also allow to return empty formelements and
            // put a question/answer only in the chatoutput to ask for more/more detailed information by the user.
            // Therefore, we do not return an error if formelements are missing.
            $outputrecord['formelements'] = $this->validate_formelements($outputrecord['formelements']);
        }

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

    /**
     * Extracts the JSON properly from a string, also respecting { symbols inside the JSON.}
     *
     * @param string $text the JSON string possibly with extra text around it.
     * @return ?array the JSON object as associative array or null if none found.
     */
    private function extract_single_json_object(string $text): ?array {
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }
        $depth = 0;
        $jsonstring = '';
        for ($i = $start, $len = strlen($text); $i < $len; $i++) {
            if ($text[$i] === '{') {
                $depth++;
            }
            if ($depth > 0) {
                $jsonstring .= $text[$i];
            }
            if ($text[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
        }
        if ($jsonstring) {
            $decoded = json_decode($jsonstring, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }
        return null;
    }
}

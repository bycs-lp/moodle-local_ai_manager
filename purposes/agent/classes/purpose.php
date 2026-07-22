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
        $genericprompt = get_config('aipurpose_agent', 'agentprompt');

        // Add formelement options.
        $formelementoptionsjson = json_encode(['formelements' => $this->storedoptions['agentoptions']['formelements']]);
        $formattedprompt = str_replace('{{formelementsjson}}', $formelementoptionsjson, $genericprompt);
        $formattedprompt = str_replace(
            '{{currentlang}}',
            Locale::getDisplayLanguage(current_language(), 'en'),
            $formattedprompt
        );
        $formattedprompt = str_replace('{{pageid}}', $this->storedoptions['agentoptions']['pageid'], $formattedprompt);

        // Build the conversation context:
        // 1. Agent prompt as system message (always first).
        // 2. Additional context if available.
        // 3. Conversation history from previous messages.
        // The user's current prompt (prompttext) is appended by the connector automatically.
        $conversationcontext = [];

        // Append additional context to the system prompt if available.
        if (!empty($this->storedoptions['agentoptions']['additionalcontext'])) {
            $formattedprompt .= "\n\n\n# Additional context\n\n"
                . 'Here is some additional context for the assignment the user prompt will give you:'
                . "\n\n"
                . $this->storedoptions['agentoptions']['additionalcontext'];
        }

        // Agent prompt (including additional context) as system instruction.
        $conversationcontext[] = [
            'sender' => 'system',
            'message' => $formattedprompt,
        ];

        // Append the conversation history after the system prompt and additional context.
        $currentconversationcontext = $options['conversationcontext'] ?? [];
        $conversationcontext = array_merge($conversationcontext, $currentconversationcontext);

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
        // In case the AI answers with plain text, we return it as JSON and without any form elements or suggestions.
        // Unfortunately, this means, we also return malformed and not parseable JSON as plain text, because we cannot
        // distinguish a bad JSON from the LLM that sends plain text.
        if (empty($outputrecord)) {
            return json_encode([
                'formelements' => [],
                'chatoutput' => [
                    [
                        'type' => 'intro',
                        // Non-JSON answers still go through the formatting pipeline so math and HTML render.
                        'text' => $this->format_ai_markdown_output($output, ['filter' => false]),
                    ],
                    [
                        'type' => 'outro',
                        'text' => '',
                    ],
                ],
            ]);
        }

        // Checking the formelements in the response.
        if (!empty($outputrecord['formelements'])) {
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

        // Format formelements text fields.
        foreach ($outputrecord['formelements'] as $key => $formelement) {
            // Sanitize label, name and id - these should not contain HTML, just strip tags as a safety measure.
            if (isset($formelement['label'])) {
                $outputrecord['formelements'][$key]['label'] = strip_tags($formelement['label']);
            }
            if (isset($formelement['name'])) {
                $outputrecord['formelements'][$key]['name'] = strip_tags($formelement['name']);
            }
            if (isset($formelement['id'])) {
                $outputrecord['formelements'][$key]['id'] = strip_tags($formelement['id']);
            }
            // Explanations may mention raw HTML tags like <pre> that must be escaped before Markdown conversion.
            if (isset($formelement['explanation'])) {
                $outputrecord['formelements'][$key]['explanation'] = $this->format_ai_markdown_output(
                    $formelement['explanation'],
                    ['filter' => false]
                );
            }
            // The newValue must reach the form field as-is; only this separate display copy is formatted.
            if (isset($formelement['newValue'])) {
                $outputrecord['formelements'][$key]['suggestiondisplayvalue'] = $this->format_ai_markdown_output(
                    $formelement['newValue'],
                    ['filter' => false]
                );
            }
        }

        // Checking the correct structure of chat output.
        $outputrecord['chatoutput'] = $this->validate_chatoutput($outputrecord['chatoutput']);
        foreach ($outputrecord['chatoutput'] as $key => $outputobject) {
            $normalizedtext = $this->normalize_chatoutput_newlines($outputobject['text']);
            // Use format_ai_markdown_output() instead of format_text() directly so that
            // MathJax escaping and HTML tag escaping are applied consistently.
            $outputrecord['chatoutput'][$key]['text'] = $this->format_ai_markdown_output(
                $normalizedtext,
                ['filter' => false]
            );
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
        $instring = false;
        $escaped = false;
        for ($i = $start, $len = strlen($text); $i < $len; $i++) {
            $char = $text[$i];
            // Braces inside JSON string values (e.g. "text": "closing } brace") must not
            // affect the depth counting, so the string state is tracked including escapes.
            if (!$instring && $char === '{') {
                $depth++;
            }
            if ($depth > 0) {
                $jsonstring .= $char;
            }
            if ($instring) {
                if ($escaped) {
                    $escaped = false;
                } else if ($char === '\\') {
                    $escaped = true;
                } else if ($char === '"') {
                    $instring = false;
                }
            } else if ($char === '"') {
                $instring = true;
            } else if ($char === '}') {
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
            // The most common LLM noncompliance is a single (unescaped) backslash from LaTeX
            // content, which makes the whole JSON object unparseable. Retry exactly once with
            // repaired backslash escapes before falling back to plain text output.
            $decoded = json_decode($this->repair_json_backslash_escapes($jsonstring), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }
        return null;
    }

    /**
     * Repairs unescaped backslashes in an invalid JSON string returned by the LLM.
     *
     * LaTeX content consists almost entirely of single backslashes which are invalid JSON
     * escapes. A blanket doubling of all backslashes would destroy legitimate escapes like
     * "\n" used for line breaks in prose fields. Therefore recognizable math segments
     * (where every backslash must be doubled, including valid-looking escapes like \frac
     * or \times) are masked first, and outside of them only clearly invalid escape
     * sequences are repaired while all valid JSON escapes are kept untouched.
     *
     * The math masking reuses base_purpose::mask_math_segments(); here every backslash inside a masked
     * segment is doubled so it survives as a valid JSON escape.
     *
     * @param string $json the JSON string that failed to decode
     * @return string the repaired JSON string (may still be invalid)
     */
    private function repair_json_backslash_escapes(string $json): string {
        // Mask math segments, doubling every backslash inside them.
        $segments = [];
        $json = self::mask_math_segments($json, fn($segment) => str_replace('\\', '\\\\', $segment), $segments);

        // Outside math segments: keep valid JSON escapes, double the backslash of invalid ones.
        // The callback consumes valid escapes completely, so an already escaped backslash
        // followed by a letter (e.g. "\\underline") can never be matched a second time.
        $json = preg_replace_callback(
            '/\\\\(u[0-9a-fA-F]{4}|["\\\\\/bfnrt])|\\\\(.)/s',
            function ($matches) {
                if (isset($matches[2])) {
                    return '\\\\' . $matches[2];
                }
                return $matches[0];
            },
            $json
        );

        // Restore the repaired math segments.
        return str_replace(array_keys($segments), array_values($segments), $json);
    }

    /**
     * Normalizes chat text for Markdown rendering by doubling isolated line breaks.
     *
     * Supports AI responses that contain either real newlines or literal "\\n" sequences.
     * Existing consecutive line breaks are kept unchanged.
     *
     * @param string $text Raw chat text from AI response.
     * @return string Normalized text for Markdown processing.
     */
    private function normalize_chatoutput_newlines(string $text): string {
        // Some models return literal "\n" in JSON string values; normalize these first.
        if (!str_contains($text, "\n") && str_contains($text, '\\n')) {
            $text = str_replace('\\n', "\n", $text);
        }

        // Double isolated single newlines so Markdown renders lists and paragraphs correctly, but skip
        // fenced code blocks (even indices are outside fences): doubling inside a fence injects blank
        // lines and makes MarkdownExtra emit literal <br /> tags into the code.
        $parts = preg_split('/(\x60{3}.*?\x60{3})/s', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $doubled = preg_replace('/(?<!\n)\n(?!\n)/', "\n\n", $part);
                if ($doubled !== null) {
                    $parts[$index] = $doubled;
                }
            }
        }

        return implode('', $parts);
    }

    /**
     * Returns the default value for the agentprompt setting.
     *
     * Used to seed the admin setting on install and to overwrite it on upgrade when the default changes.
     * After install/upgrade the admin setting is authoritative.
     *
     * @return string The default agent prompt.
     */
    public static function get_default_agentprompt(): string {
        $formattingprompt = self::get_default_formatting_prompt();
        return <<<EOF
This system prompt has the following structure:

* Model instructions
* Form structure, current values & help strings
optional: * Additional context

# Model instructions

I'll pass you Moodle help texts and form elements related to the page with id {{pageid}}. This prompt will be followed by a list
 of prompt and prompt completion pairs as conversation context. Based on the user prompt which will be the last user message give
 suggestions on how to populate the input fields. You can ask follow-up questions from the user if needed.
 Answer always in the language of the user prompt (the last prompt). If the language cannot be determined, use {{currentlang}}.

This is an example output JSON:

{
    "formelements": [
        {
            "id": "id_name",
            "label": "the label that has been sent as context to you for this element",
            "name": "name",
            "newValue": "",
            "explanation": ""
        },
    ],
    "chatoutput": [
        {
            "type": "intro",
            "text": "introtext"
        },
        {
            "type": "outro",
            "text": "outrotext"
        }
    ]
}

"newValue" is the new value that you suggest, and "explanation" is the reasoning shown to the user. All single objects in the
 "formelements" array always must have the exact same structure which means they must have all of the 5 attributes.

Do not suggest settings that depend on other course contexts that you are not aware of, unless the user provides this information
 in the following message.
Do not create an entry for values that are already set according to your suggestions, but include them later on in the intro or
 outro attributes of the return JSON.

In addition to formelements, the JSON has another key called "chatoutput". All your output to the user should be put there:
"introtext" is what you are outputting before the formelements, describing briefly why you chose the settings like you did and
 include some explanation of the settings that are already set according to your suggestion instead of including them in the
 object of the formfields attribute in the JSON.
"outrotext" is what you are outputting after the formelements, for example, for a helpful followup question.

{$formattingprompt}

Exception: The "newValue" field is inserted directly into the target form field, it is not
rendered through the normal chat display pipeline. Never wrap "newValue" content in fenced
code blocks, regardless of format - the target form field would show the fence markers literally.

Check the "editorFormat" property of the corresponding form element in the form structure JSON:
- editorFormat "html": "newValue" must contain the raw, directly usable HTML exactly as it
  should appear in the rich text editor. Do not use Markdown syntax here.
  When it contains a code block, write it as <pre class="language-xxx"><code>...</code></pre>
  with the language class on the <pre> element (e.g. language-python), so it gets syntax
  highlighting after saving.
- any other editorFormat (or if missing): "newValue" must contain plain text or Markdown syntax
  as appropriate for the field, and must never contain raw HTML tags.

Because your entire answer is a single JSON object, all string values must use valid JSON
escaping: every literal backslash must be written as a double backslash. This is especially
important for LaTeX/MathJax content where every delimiter and command starts with a backslash.
Correct example:

"newValue": "The area is \\\\(A = \\\\frac{1}{2} \\\\cdot g \\\\cdot h\\\\)."

Never write a single backslash before characters like ( ) [ ] { } or letters inside JSON strings.

All of your output MUST ALWAYS be inside the JSON structure.
DO ONLY RETURN A VALID JSON OBJECT.

# Form structure, current values & help strings, encoded as JSON string

{{formelementsjson}}
EOF;
    }
}

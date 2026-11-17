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

namespace aipurpose_searchquery;

use local_ai_manager\base_purpose;
use local_ai_manager\local\requestoptions\conversationcontext;

/**
 * Purpose for generating a search query suitable for embedding based retrieval.
 *
 * The prompt is the latest message of the user, the conversation history is passed via the conversationcontext option. The
 * AI tool rewrites the message into a self-contained search query which can then be embedded and used for a vector search.
 *
 * @package    aipurpose_searchquery
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purpose extends base_purpose {
    #[\Override]
    public function get_additional_request_options(array $options): array {
        $systemprompt = self::get_default_systemprompt();
        $conversationcontext = $options[conversationcontext::OPTION_NAME] ?? [];

        // Some LLM APIs require exactly one message with role 'system', so we prepend our instructions to an existing one.
        $systemindex = null;
        foreach ($conversationcontext as $index => $entry) {
            if ($entry['sender'] === 'system') {
                $systemindex = $index;
                break;
            }
        }
        if ($systemindex !== null) {
            $conversationcontext[$systemindex]['message'] =
                $systemprompt . "\n\n" . $conversationcontext[$systemindex]['message'];
        } else {
            array_unshift($conversationcontext, ['sender' => 'system', 'message' => $systemprompt]);
        }

        return [conversationcontext::OPTION_NAME => $conversationcontext];
    }

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

    /**
     * Returns the plain search query without any Markdown/HTML formatting.
     *
     * The result is not displayed, but embedded, so it must not be converted to HTML.
     *
     * @param string $output the search query returned by the AI tool
     * @return string the trimmed search query
     */
    #[\Override]
    public function format_output(string $output): string {
        return trim($output);
    }

    #[\Override]
    public function is_internal(): bool {
        return true;
    }

    /**
     * Returns the system prompt instructing the AI tool to generate a search query.
     *
     * @return string the system prompt
     */
    public static function get_default_systemprompt(): string {
        return <<<EOF
You generate search queries for a semantic vector search (embedding based retrieval) over teaching materials.
Rewrite the latest user message into exactly one self-contained search query:
- Resolve pronouns and references to earlier messages by using the conversation history.
- Keep all relevant key terms, names, topics and concepts, drop greetings, filler words and instructions about the answer format.
- Write the query in the language of the latest user message.
- Do not answer the question.
Output only the search query as plain text, without quotes, explanations or formatting.
EOF;
    }
}


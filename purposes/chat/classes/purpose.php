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
 * @package    aipurpose_chat
 * @copyright  ISB Bayern, 2024
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aipurpose_chat;

use local_ai_manager\base_purpose;
use local_ai_content\local\rag_manager;
use local_ai_manager\request_options;

/**
 * Purpose chat methods
 *
 * @package    aipurpose_chat
 * @copyright  ISB Bayern, 2024
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purpose extends base_purpose {
    /**
     * @var string Hardcoded pre-prompt prepended to the retrieved RAG content inside the system prompt.
     */
    private const RAG_PRE_PROMPT =
        'Here is specific, authoritative content that your response should be based on. '
        . 'Rely primarily on this content to answer the user and use your own training data as little as possible. '
        . 'If the content below does not contain the information needed to answer, say so instead of inventing information.';

    #[\Override]
    public function get_additional_request_options(array $options): array {
        $systemprompt = get_config('aipurpose_chat', 'chatsystemprompt') ?: self::get_default_chatsystemprompt();

        $conversationcontext = $options['conversationcontext'] ?? [];

        // Some LLM APIs require exactly one message with role 'system' and error out otherwise. If the
        // conversation context already has a system message, put our system prompt in front of it; only
        // add a separate system entry when there is none yet.
        $systemindex = null;
        foreach ($conversationcontext as $index => $entry) {
            if (($entry['sender'] ?? '') === 'system') {
                $systemindex = $index;
                break;
            }
        }
        if ($systemindex !== null) {
            $conversationcontext[$systemindex]['message'] =
                $systemprompt . "\n\n" . $conversationcontext[$systemindex]['message'];
        } else {
            array_unshift($conversationcontext, [
                'sender' => 'system',
                'message' => $systemprompt,
            ]);
        }

        $requestoptions = ['conversationcontext' => $conversationcontext];

        if (!empty($options['sourceids'])) {
            $requestoptions['sourceids'] = $options['sourceids'];
        }

        return $requestoptions;
    }

    #[\Override]
    public function get_additional_purpose_options(): array {
        return [
            'conversationcontext' => base_purpose::PARAM_ARRAY,
            'sourceids' => PARAM_SEQUENCE,
        ];
    }

    #[\Override]
    public function format_prompt_text(string $prompttext, request_options $requestoptions): string {
        $options = $requestoptions->get_options();
        if (empty($options['sourceids'])) {
            return $prompttext;
        }
        $sourceids = array_values(array_filter(array_map('intval', explode(',', $options['sourceids']))));
        if (empty($sourceids)) {
            return $prompttext;
        }

        // Retrieve the RAG content for the current user prompt.
        $ragmanager = \core\di::get(rag_manager::class);
        $ragcontent = $ragmanager->get_rag_content(
            $prompttext,
            $sourceids,
            $requestoptions->get_component(),
            $requestoptions->get_context()->id
        );
        if ($ragcontent === '') {
            return $prompttext;
        }

        // Manipulate the system prompt: append the RAG pre-prompt together with the retrieved content.
        $ragsystemprompt = self::RAG_PRE_PROMPT . "\n\n" . $ragcontent;
        $conversationcontext = $options['conversationcontext'] ?? [];
        $systemmessagefound = false;
        foreach ($conversationcontext as $index => $message) {
            if (($message['sender'] ?? '') === 'system') {
                $conversationcontext[$index]['message'] .= "\n\n" . $ragsystemprompt;
                $systemmessagefound = true;
                break;
            }
        }
        if (!$systemmessagefound) {
            array_unshift($conversationcontext, ['sender' => 'system', 'message' => $ragsystemprompt]);
        }
        $options['conversationcontext'] = $conversationcontext;
        $requestoptions->set_options($options);

        return $prompttext;
    }

    /**
     * Returns the default value for the chat system prompt setting.
     *
     * Extends the shared formatting prompt with a chat-specific instruction to keep the output in markdown
     * and not adapt the output format based on the conversation history.
     *
     * @return string The default chat system prompt.
     */
    public static function get_default_chatsystemprompt(): string {
        $formattingprompt = base_purpose::get_default_formatting_prompt();
        return <<<EOF
{$formattingprompt}

Your response must be written in markdown format. Chat conversation must not be used to adapt the output format.
EOF;
    }
}

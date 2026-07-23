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

/**
 * Purpose chat methods
 *
 * @package    aipurpose_chat
 * @copyright  ISB Bayern, 2024
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purpose extends base_purpose {
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

        return ['conversationcontext' => $conversationcontext];
    }

    #[\Override]
    public function get_additional_purpose_options(): array {
        return ['conversationcontext' => base_purpose::PARAM_ARRAY];
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

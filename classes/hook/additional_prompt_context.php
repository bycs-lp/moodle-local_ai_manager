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

namespace local_ai_manager\hook;

use local_ai_manager\request_options;

/**
 * Hook for adding additional context to an AI request.
 *
 * Dispatched for every AI request before the purpose formats the prompt. Listeners can add context for the AI tool, for
 * example content retrieved for the prompt (RAG). Purposes supporting the 'conversationcontext' option get the added
 * context appended to their system message
 * (see {@see \local_ai_manager\local\requestoptions\conversationcontext::append_to_system_message()}), all other purposes
 * decide themselves how to pass it to the AI tool (see {@see \local_ai_manager\base_purpose::add_prompt_contexts()}). Listeners
 * can also add result data, which is stored in the request options under the key 'contextdata' and therefore ends up in the
 * request log, so the calling component can show it next to the response.
 *
 * Input for listeners is passed via the request options, for example the generic option 'sourceids'.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to add additional context to an AI request.')]
#[\core\attribute\tags('local_ai_manager')]
final class additional_prompt_context {
    /** @var string[] The added contexts in the order they have been added. */
    private array $contexts = [];

    /** @var array[] The added result data indexed by key. */
    private array $resultdata = [];

    /**
     * Constructor.
     *
     * @param string $prompttext The prompt text of the user.
     * @param request_options $requestoptions The sanitized request options, including purpose, context and component.
     */
    public function __construct(
        /** @var string The prompt text of the user. */
        public readonly string $prompttext,
        /** @var request_options The sanitized request options, including purpose, context and component. */
        public readonly request_options $requestoptions,
    ) {
    }

    /**
     * Adds a context for the AI tool.
     *
     * @param string $context The context text, for example retrieved excerpts including instructions how to use them.
     */
    public function add_context(string $context): void {
        $this->contexts[] = $context;
    }

    /**
     * Returns the added contexts.
     *
     * @return string[] The added contexts in the order they have been added.
     */
    public function get_contexts(): array {
        return $this->contexts;
    }

    /**
     * Sets result data to be stored with the request.
     *
     * @param string $key The key of the data, should be unique for the listener, for example 'ragresult'.
     * @param array $data The data, has to be JSON encodable.
     */
    public function set_result_data(string $key, array $data): void {
        $this->resultdata[$key] = $data;
    }

    /**
     * Returns the added result data.
     *
     * @return array[] The added result data indexed by key.
     */
    public function get_result_data(): array {
        return $this->resultdata;
    }
}


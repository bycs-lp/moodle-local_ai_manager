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
 * LLM tool-response value object (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent;

/**
 * Parsed structured response from an LLM.
 *
 * Unifies native tool-call responses (OpenAI/Gemini/Ollama) and emulated JSON
 * blocks (Telli) into one representation the orchestrator can loop on.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class tool_response {

    /** Action value: LLM produced a final answer. */
    public const ACTION_FINAL = 'final';
    /** Action value: LLM produced one or more tool calls. */
    public const ACTION_TOOL_CALL = 'tool_call';

    /**
     * Constructor.
     *
     * @param string $action one of ACTION_FINAL, ACTION_TOOL_CALL
     * @param string|null $final_text non-null when $action === ACTION_FINAL
     * @param array $calls list of {id, tool, arguments} when $action === ACTION_TOOL_CALL
     * @param array $raw untouched provider response for debug trace
     */
    public function __construct(
        public readonly string $action,
        public readonly ?string $final_text = null,
        public readonly array $calls = [],
        public readonly array $raw = [],
    ) {
    }

    /**
     * Factory for a final-answer response.
     *
     * @param string $text
     * @param array $raw
     * @return self
     */
    public static function final(string $text, array $raw = []): self {
        return new self(self::ACTION_FINAL, $text, [], $raw);
    }

    /**
     * Factory for a tool-call response.
     *
     * @param array $calls list of {id, tool, arguments}
     * @param array $raw
     * @return self
     */
    public static function tool_calls(array $calls, array $raw = []): self {
        return new self(self::ACTION_TOOL_CALL, null, $calls, $raw);
    }
}

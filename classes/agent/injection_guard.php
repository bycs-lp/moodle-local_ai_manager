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
 * Prompt-injection guard (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent;

/**
 * Defensive wrapper for external/untrusted content that ends up in LLM context.
 *
 * Any data the agent obtains from a tool call that may contain user-generated content
 * (forum posts, file contents, WS responses derived from user input) goes through
 * {@see wrap_untrusted()} before it is concatenated into the LLM prompt. The wrapper:
 *
 *   - Escapes the content so embedded XML-tags cannot close the wrapper.
 *   - Surrounds it with {@code <untrusted_data source="..."> ... </untrusted_data>}
 *     and instructs the model (in the system prompt) to treat the contents as data
 *     rather than instructions.
 *   - Records the consumption against the current run so {@see trust_resolver} can
 *     disable global trust for the next 2 turns.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class injection_guard {

    /** Run-level flag key inside local_ai_manager_agent_runs.entity_context. */
    private const FLAG_KEY = '_untrusted_last_turn';

    /**
     * Wrap untrusted content for safe LLM consumption.
     *
     * @param string $content raw content
     * @param string $source short source label (will be attribute-escaped)
     * @return string wrapped and escaped content ready to embed in a prompt
     */
    public function wrap_untrusted(string $content, string $source): string {
        $escapedsource = htmlspecialchars($source, ENT_QUOTES | ENT_XML1, 'UTF-8');
        // ENT_XML1 replaces < > & ' " with entities; critically prevents embedded </untrusted_data>
        // from escaping the wrapper.
        $escapedcontent = htmlspecialchars($content, ENT_QUOTES | ENT_XML1, 'UTF-8');
        return '<untrusted_data source="' . $escapedsource . '">'
            . "\n" . $escapedcontent . "\n"
            . '</untrusted_data>';
    }

    /**
     * Mark an agent run as having consumed untrusted data on the given turn.
     *
     * @param int $runid
     * @param int $turn
     * @return void
     */
    public function mark_consumed(int $runid, int $turn): void {
        global $DB;
        $record = $DB->get_record('local_ai_manager_agent_runs', ['id' => $runid], 'id, entity_context');
        if (!$record) {
            return;
        }
        $context = $record->entity_context ? json_decode($record->entity_context, true) : [];
        if (!is_array($context)) {
            $context = [];
        }
        $context[self::FLAG_KEY] = $turn;
        $DB->update_record('local_ai_manager_agent_runs', (object) [
            'id' => $runid,
            'entity_context' => json_encode($context, JSON_UNESCAPED_UNICODE),
            'timemodified' => \core\di::get(\core\clock::class)->now()->getTimestamp(),
        ]);
    }

    /**
     * Check whether the current run has consumed untrusted data within the last two turns.
     *
     * @param int $runid
     * @return bool
     */
    public function run_consumed_untrusted_data(int $runid): bool {
        global $DB;
        $record = $DB->get_record('local_ai_manager_agent_runs', ['id' => $runid], 'iterations, entity_context');
        if (!$record) {
            return false;
        }
        $context = $record->entity_context ? json_decode($record->entity_context, true) : [];
        if (!is_array($context) || !isset($context[self::FLAG_KEY])) {
            return false;
        }
        $lastconsumed = (int) $context[self::FLAG_KEY];
        return ((int) $record->iterations - $lastconsumed) <= 2;
    }
}

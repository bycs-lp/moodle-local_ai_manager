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
 * External function: keyword-based auto-promote intent check (MBS-10761 §7.6).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_ai_manager\agent\tool_registry;

/**
 * Lightweight, client-triggered intent check used to suggest a mode switch
 * (chat → toolagent) when the user's draft message matches one or more tool
 * keyword sets.
 *
 * Deliberately keyword-based and local: no LLM call, no logging, bounded
 * compute. Invoked client-side after a short debounce on the message input.
 * The block can use the response to render an inline autopromote hint
 * ("It looks like you want to act on a course — switch to tool mode?").
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class agent_check_intent extends external_api {

    /** @var int Maximum number of matched tools to return. */
    private const MAX_MATCHES = 5;

    /** @var int Score threshold above which a mode switch is suggested. */
    private const SUGGEST_THRESHOLD = 2;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'text' => new external_value(PARAM_RAW, 'Draft message text (unsanitised, max 4000 chars).'),
            'contextid' => new external_value(PARAM_INT, 'Context id in which the check runs.', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Execute.
     *
     * @param string $text
     * @param int $contextid
     * @return array
     */
    public static function execute(string $text, int $contextid = 0): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'text' => $text,
            'contextid' => $contextid,
        ]);

        $ctx = $params['contextid'] > 0
            ? \core\context::instance_by_id($params['contextid'], IGNORE_MISSING)
            : \core\context\system::instance();
        if (!$ctx) {
            $ctx = \core\context\system::instance();
        }
        self::validate_context($ctx);
        require_capability('local/ai_manager:use', $ctx);

        $text = \core_text::substr(trim($params['text']), 0, 4000);
        if ($text === '') {
            return [
                'score' => 0,
                'suggestedMode' => 'chat',
                'matchedTools' => [],
            ];
        }

        // Normalise to lowercase and strip punctuation for keyword matching.
        $needle = ' ' . preg_replace('/[\p{P}\p{S}]+/u', ' ', \core_text::strtolower($text)) . ' ';

        $registry = \core\di::get(tool_registry::class);
        $tools = $registry->get_tools_for($USER, $ctx);

        $matches = [];
        foreach ($tools as $tool) {
            $keywords = $tool->get_keywords();
            if (empty($keywords)) {
                continue;
            }
            $hits = 0;
            foreach ($keywords as $keyword) {
                $kw = \core_text::strtolower(trim((string) $keyword));
                if ($kw === '') {
                    continue;
                }
                if (str_contains($needle, ' ' . $kw . ' ') || str_contains($needle, ' ' . $kw)) {
                    $hits++;
                }
            }
            if ($hits > 0) {
                $matches[] = [
                    'tool' => $tool->get_name(),
                    'category' => $tool->get_category(),
                    'hits' => $hits,
                ];
            }
        }

        // Sort by hit-count desc, then by name for stable output.
        usort($matches, function ($a, $b) {
            return [$b['hits'], $a['tool']] <=> [$a['hits'], $b['tool']];
        });
        $matches = array_slice($matches, 0, self::MAX_MATCHES);

        $score = array_sum(array_column($matches, 'hits'));
        $suggested = $score >= self::SUGGEST_THRESHOLD ? 'toolagent' : 'chat';

        return [
            'score' => (int) $score,
            'suggestedMode' => $suggested,
            'matchedTools' => $matches,
        ];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'score' => new external_value(PARAM_INT, 'Aggregate keyword hit count'),
            'suggestedMode' => new external_value(PARAM_ALPHANUMEXT, 'Suggested conversation mode'),
            'matchedTools' => new external_multiple_structure(
                new external_single_structure([
                    'tool' => new external_value(PARAM_ALPHANUMEXT, 'Tool machine name'),
                    'category' => new external_value(PARAM_ALPHANUMEXT, 'Tool category'),
                    'hits' => new external_value(PARAM_INT, 'Matched keyword count for this tool'),
                ]),
                'Tools matched by keyword',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }
}

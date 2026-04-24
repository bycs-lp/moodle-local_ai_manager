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
 * Entity tracker for pronominal resolution (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent;

/**
 * Track recently seen Moodle entities per agent run for pronoun resolution.
 *
 * Stores a small JSON structure in agent_run.entity_context:
 *   {"recent":[{"type":"course","id":12,"label":"Physik 8a","turn":2}, ...]}
 *
 * Limit: 5 entries per type, LRU. TTL: 24 hours since last_mentioned_turn.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class entity_tracker {

    /** Maximum entities tracked per type. */
    public const MAX_PER_TYPE = 5;

    /** Time-to-live for a tracked entity since last mention, in seconds. */
    public const TTL_SECONDS = 24 * HOURSECS;

    /**
     * Push a newly seen entity into the context.
     *
     * @param array $context current entity_context payload
     * @param string $type entity type (course, quiz, forum, ...)
     * @param int $id moodle ID
     * @param string $label human-readable label
     * @param int $turn current run iteration
     * @return array updated entity_context payload
     */
    public function push(array $context, string $type, int $id, string $label, int $turn): array {
        $recent = $context['recent'] ?? [];

        // Drop an existing entry for the same (type, id) so the refreshed one goes to the front.
        $recent = array_values(array_filter($recent, fn($e) => !($e['type'] === $type && $e['id'] === $id)));

        array_unshift($recent, [
            'type' => $type,
            'id' => $id,
            'label' => $label,
            'turn' => $turn,
        ]);

        // Enforce per-type quota.
        $bytype = [];
        $trimmed = [];
        foreach ($recent as $entry) {
            $bytype[$entry['type']] = ($bytype[$entry['type']] ?? 0) + 1;
            if ($bytype[$entry['type']] > self::MAX_PER_TYPE) {
                continue;
            }
            $trimmed[] = $entry;
        }

        return ['recent' => $trimmed];
    }

    /**
     * Remove an entity from the context (e.g. after a course_deleted event).
     *
     * @param array $context
     * @param string $type
     * @param int $id
     * @return array
     */
    public function invalidate(array $context, string $type, int $id): array {
        $recent = $context['recent'] ?? [];
        $recent = array_values(array_filter($recent, fn($e) => !($e['type'] === $type && $e['id'] === $id)));
        return ['recent' => $recent];
    }

    /**
     * Resolve a pronominal reference ("this course", "dieses Quiz") to the most recent entity of that type.
     *
     * @param array $context
     * @param string $type
     * @param int $nowtimestamp
     * @param int $runstarted timestamp when the run started (used as TTL reference)
     * @return array|null entry or null when none/expired
     */
    public function resolve(array $context, string $type, int $nowtimestamp, int $runstarted): ?array {
        foreach ($context['recent'] ?? [] as $entry) {
            if ($entry['type'] !== $type) {
                continue;
            }
            // TTL check relative to the run start timestamp.
            if (($nowtimestamp - $runstarted) > self::TTL_SECONDS) {
                return null;
            }
            return $entry;
        }
        return null;
    }
}

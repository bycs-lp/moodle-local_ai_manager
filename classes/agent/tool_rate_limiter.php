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
 * Per-user hourly rate limiter for agent tool calls (MBS-10761 Paket 3, §10.5).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent;

use local_ai_manager\agent\exception\rate_limit_exceeded_exception;

/**
 * Keeps a rolling hourly counter per (userid, toolname) in the MUC cache
 * {@see \cache::make('local_ai_manager', 'agent_ratelimits')}.
 *
 * Limits are resolved in this order:
 *   1. Per-tool admin setting `agent_ratelimit_<toolname>` (integer, 0 = disabled).
 *   2. Default for read tools (`agent_ratelimit_read_default`, default 200/h).
 *   3. Default for write tools (`agent_ratelimit_write_default`, default 50/h).
 *
 * A tool counts as "write" when its {@see tool_definition::requires_approval()}
 * returns true — this matches the spec's read/write distinction (§10.5).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_rate_limiter {

    /** Fallback for read-only tools when no admin setting is present. */
    public const DEFAULT_READ_PER_HOUR = 200;

    /** Fallback for write/approval-required tools when no admin setting is present. */
    public const DEFAULT_WRITE_PER_HOUR = 50;

    /**
     * Constructor.
     *
     * @param \core\clock $clock
     */
    public function __construct(
        private readonly \core\clock $clock,
    ) {
    }

    /**
     * Resolve the effective per-hour limit for the given tool.
     *
     * @param tool_definition $tool
     * @return int limit in calls per hour, 0 means unlimited
     */
    public function get_limit_for(tool_definition $tool): int {
        $sanitisedname = preg_replace('/[^a-z0-9_]/i', '_', $tool->get_name());
        $pertool = get_config('local_ai_manager', 'agent_ratelimit_' . $sanitisedname);
        if ($pertool !== false && $pertool !== '') {
            return (int) $pertool;
        }
        if ($tool->requires_approval()) {
            $write = get_config('local_ai_manager', 'agent_ratelimit_write_default');
            return (int) ($write !== false && $write !== '' ? $write : self::DEFAULT_WRITE_PER_HOUR);
        }
        $read = get_config('local_ai_manager', 'agent_ratelimit_read_default');
        return (int) ($read !== false && $read !== '' ? $read : self::DEFAULT_READ_PER_HOUR);
    }

    /**
     * Check the current count and increment on success.
     *
     * @param int $userid
     * @param tool_definition $tool
     * @throws rate_limit_exceeded_exception when the limit has been reached
     */
    public function check_and_increment(int $userid, tool_definition $tool): void {
        $limit = $this->get_limit_for($tool);
        if ($limit <= 0) {
            return;
        }
        $cache = \cache::make('local_ai_manager', 'agent_ratelimits');
        $key = $this->cache_key($userid, $tool->get_name());
        $count = (int) $cache->get($key);
        if ($count >= $limit) {
            throw new rate_limit_exceeded_exception($tool->get_name(), $limit);
        }
        $cache->set($key, $count + 1);
    }

    /**
     * Read the current count (useful for UI / tests).
     *
     * @param int $userid
     * @param string $toolname
     * @return int
     */
    public function current_count(int $userid, string $toolname): int {
        $cache = \cache::make('local_ai_manager', 'agent_ratelimits');
        return (int) $cache->get($this->cache_key($userid, $toolname));
    }

    /**
     * Build the MUC key for the current hour bucket.
     *
     * @param int $userid
     * @param string $toolname
     * @return string
     */
    private function cache_key(int $userid, string $toolname): string {
        $bucket = (int) floor($this->clock->now()->getTimestamp() / HOURSECS);
        $sanitisedname = preg_replace('/[^a-z0-9_]/i', '_', $toolname);
        return 'u' . $userid . '_t' . $sanitisedname . '_b' . $bucket;
    }
}

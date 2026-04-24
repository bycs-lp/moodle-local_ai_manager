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
 * Agent rate-limit exception (MBS-10761 Paket 3).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent\exception;

/**
 * Raised when a tool call would exceed the per-user hourly rate limit.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rate_limit_exceeded_exception extends \moodle_exception {

    /**
     * Constructor.
     *
     * @param string $toolname the tool name that tripped the limit
     * @param int $limit the hourly limit in effect
     */
    public function __construct(
        public readonly string $toolname,
        public readonly int $limit,
    ) {
        parent::__construct('error_ratelimitexceeded', 'local_ai_manager', '', (object) [
            'tool' => $toolname,
            'limit' => $limit,
        ]);
    }
}

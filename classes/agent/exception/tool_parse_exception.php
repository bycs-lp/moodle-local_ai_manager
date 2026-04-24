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
 * Agent-specific exceptions (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent\exception;

/**
 * Raised when the LLM output cannot be parsed into a tool_response.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_parse_exception extends \moodle_exception {

    /**
     * Constructor.
     *
     * @param string $reason stable machine-readable reason code
     * @param string $rawoutput first 2kB of the raw LLM output (truncated)
     */
    public function __construct(string $reason, string $rawoutput = '') {
        parent::__construct('error_toolparse', 'local_ai_manager', '', (object) [
            'reason' => $reason,
            'output' => mb_substr($rawoutput, 0, 2048),
        ]);
    }
}

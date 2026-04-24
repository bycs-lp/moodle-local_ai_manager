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
 * Raised when an HMAC approval token fails verification (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent\exception;

/**
 * Invalid-token exception.
 *
 * Reasons:
 *  - malformed: token could not be decoded
 *  - expired:   token TTL is in the past
 *  - invalid:   signature mismatch (manipulation or rotated secret)
 *  - reused:    run/call-index combo already consumed
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class invalid_token_exception extends \moodle_exception {

    /**
     * Constructor.
     *
     * @param string $reason one of malformed|expired|invalid|reused
     */
    public function __construct(public readonly string $reason) {
        parent::__construct('error_invalidtoken_' . $reason, 'local_ai_manager');
    }
}

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
 * Execution-context value object (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent;

/**
 * Immutable bundle of runtime information passed to every tool::execute().
 *
 * The execution_context replaces reliance on PHP superglobals and time() inside tools;
 * the clock is injected for deterministic tests (see SPEZ §15).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class execution_context {

    /**
     * Constructor.
     *
     * @param int $runid DB id of the agent run
     * @param int $callid DB id of the specific tool_call row
     * @param int $callindex ordinal position in the run
     * @param \stdClass $user moodle user record
     * @param \core\context $context moodle context the run happens in
     * @param int|null $tenantid tenant id if tenancy is enabled
     * @param int[] $draftitemids user-upload draft areas available to tools
     * @param array $entity_context {@see entity_tracker} json payload
     * @param \core\clock $clock injected clock (required for testing time-dependent tools)
     */
    public function __construct(
        public readonly int $runid,
        public readonly int $callid,
        public readonly int $callindex,
        public readonly \stdClass $user,
        public readonly \core\context $context,
        public readonly ?int $tenantid,
        public readonly array $draftitemids,
        public readonly array $entity_context,
        public readonly \core\clock $clock,
    ) {
    }
}

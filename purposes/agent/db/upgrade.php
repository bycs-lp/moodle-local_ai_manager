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
 * Upgrade steps for aipurpose_agent.
 *
 * @package    aipurpose_agent
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the aipurpose_agent plugin.
 *
 * @param int $oldversion The old version of the plugin.
 * @return bool
 */
function xmldb_aipurpose_agent_upgrade($oldversion) {
    global $CFG;
    require_once($CFG->dirroot . '/local/ai_manager/purposes/agent/db/upgradelib.php');

    // Each of the steps below changed the structure of the default agent prompt and therefore has to reset
    // the setting. Each keeps its own savepoint so already upgraded sites are not affected.
    if ($oldversion < 2026041600) {
        aipurpose_agent_reset_agentprompt_to_default();

        upgrade_plugin_savepoint(true, 2026041600, 'aipurpose', 'agent');
    }

    if ($oldversion < 2026072200) {
        aipurpose_agent_reset_agentprompt_to_default();

        upgrade_plugin_savepoint(true, 2026072200, 'aipurpose', 'agent');
    }

    if ($oldversion < 2026091600) {
        // The promptoverwritten notification must always reach admins via popup and email, so both
        // channels are forced and locked site wide instead of just enabled by default.
        aipurpose_agent_force_promptoverwritten_processors();
        aipurpose_agent_reset_agentprompt_to_default();

        upgrade_plugin_savepoint(true, 2026091600, 'aipurpose', 'agent');
    }

    return true;
}

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
    if ($oldversion < 2026041600) {
        // Overwrite the agentprompt setting with the new default value.
        set_config('agentprompt', \aipurpose_agent\purpose::get_default_agentprompt(), 'aipurpose_agent');

        upgrade_plugin_savepoint(true, 2026041600, 'aipurpose', 'agent');
    }

    if ($oldversion < 2026041601) {
        $current = get_config('aipurpose_agent', 'agentprompt');
        if (empty($current)) {
            set_config('agentprompt', \aipurpose_agent\purpose::get_default_agentprompt(), 'aipurpose_agent');
        } else {
            $formattingprompt = \local_ai_manager\base_purpose::get_default_formatting_prompt();
            if (!str_contains($current, $formattingprompt)) {
                set_config('agentprompt', $current . "\n\n" . $formattingprompt, 'aipurpose_agent');
            }
        }
        upgrade_plugin_savepoint(true, 2026041601, 'aipurpose', 'agent');
    }

    if ($oldversion < 2026041602) {
        $current = get_config('aipurpose_agent', 'agentprompt');
        if (empty($current)) {
            set_config('agentprompt', \aipurpose_agent\purpose::get_default_agentprompt(), 'aipurpose_agent');
        } else {
            $newvalueexception = \aipurpose_agent\purpose::get_newvalue_formatting_exception();
            if (!str_contains($current, $newvalueexception)) {
                set_config('agentprompt', $current . "\n\n" . $newvalueexception, 'aipurpose_agent');
            }
        }
        upgrade_plugin_savepoint(true, 2026041602, 'aipurpose', 'agent');
    }

    if ($oldversion < 2026072100) {
        $current = get_config('aipurpose_agent', 'agentprompt');
        if (empty($current)) {
            set_config('agentprompt', \aipurpose_agent\purpose::get_default_agentprompt(), 'aipurpose_agent');
        } else {
            // MBS-10879: append the agent prompt instructions added in this ticket to an existing customized
            // prompt. Each delta is only appended if missing, so re-runs and freshly generated prompts stay
            // free of duplicates.
            $deltas = [
                \local_ai_manager\base_purpose::get_mathjax_instruction(),
                \aipurpose_agent\purpose::get_json_escaping_instruction(),
                \aipurpose_agent\purpose::get_htmlcodeblock_instruction(),
            ];
            foreach ($deltas as $delta) {
                if (!str_contains($current, $delta)) {
                    $current .= "\n\n" . $delta;
                }
            }
            set_config('agentprompt', $current, 'aipurpose_agent');
        }
        upgrade_plugin_savepoint(true, 2026072100, 'aipurpose', 'agent');
    }

    return true;
}

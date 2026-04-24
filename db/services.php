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
 * External service definitions for local_ai_manager.
 *
 * @package    local_ai_manager
 * @copyright  ISB Bayern, 2024
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
        'local_ai_manager_post_query' => [
                'classname' => 'local_ai_manager\external\submit_query',
                'description' => 'Send a query to a LLM.',
                'type' => 'read',
                'ajax' => true,
                'capabilities' => 'local/ai_manager:use',
        ],
        'local_ai_manager_get_ai_config' => [
                'classname' => 'local_ai_manager\external\get_ai_config',
                'description' => 'Get all information about the current ai configuration for the current user',
                'type' => 'read',
                'ajax' => true,
                'capabilities' => 'local/ai_manager:use',
        ],
        'local_ai_manager_get_ai_info' => [
                'classname' => 'local_ai_manager\external\get_ai_info',
                'description' => 'Get general information about the AI manager',
                'type' => 'read',
                'ajax' => true,
                'capabilities' => 'local/ai_manager:use',
        ],
        'local_ai_manager_get_purpose_options' => [
                'classname' => 'local_ai_manager\external\get_purpose_options',
                'description' => 'Retrieve available options for a given purpose',
                'type' => 'read',
                'ajax' => true,
                'capabilities' => 'local/ai_manager:use',
        ],
        'local_ai_manager_get_user_quota' => [
                'classname' => 'local_ai_manager\external\get_user_quota',
                'description' => 'Retrieve quota information for the current user',
                'type' => 'read',
                'ajax' => true,
                'capabilities' => 'local/ai_manager:use',
        ],
        'local_ai_manager_vertex_cache_status' => [
                'classname' => 'local_ai_manager\external\vertex_cache_status',
                'description' => 'Fetch and update the Google Vertex AI caching status',
                'type' => 'write',
                'ajax' => true,
                'capabilities' => '',
        ],
        'local_ai_manager_get_prompts' => [
                'classname' => 'local_ai_manager\external\get_prompts',
                'description' => 'Fetch the prompts of a user in a given context',
                'type' => 'read',
                'ajax' => true,
                'capabilities' => 'local_ai_manager:viewprompts',
        ],
        'local_ai_manager_get_purposes_usage_info' => [
                'classname' => 'local_ai_manager\external\get_purposes_usage_info',
                'description' => 'Gets information about the usage of the purposes by different plugins',
                'type' => 'read',
                'ajax' => true,
                'capabilities' => 'local_ai_manager:use',
        ],
        // MBS-10761: Tool-agent approval workflow.
        'local_ai_manager_agent_approve_tool_call' => [
                'classname' => 'local_ai_manager\external\agent_approve_tool_call',
                'description' => 'Approve a pending tool call of an agent run.',
                'type' => 'write',
                'ajax' => true,
                'capabilities' => 'local/ai_manager:use',
        ],
        'local_ai_manager_agent_reject_tool_call' => [
                'classname' => 'local_ai_manager\external\agent_reject_tool_call',
                'description' => 'Reject a pending tool call of an agent run.',
                'type' => 'write',
                'ajax' => true,
                'capabilities' => 'local/ai_manager:use',
        ],
        'local_ai_manager_agent_trust_tool' => [
                'classname' => 'local_ai_manager\external\agent_trust_tool',
                'description' => 'Mark a tool as trusted for the current session, user or tenant.',
                'type' => 'write',
                'ajax' => true,
                'capabilities' => 'local/ai_manager:use',
        ],
        'local_ai_manager_agent_run_start' => [
                'classname' => 'local_ai_manager\external\agent_run_start',
                'description' => 'Start or resume an agent run.',
                'type' => 'write',
                'ajax' => true,
                'capabilities' => 'local/ai_manager:use',
        ],
        'local_ai_manager_agent_abort_run' => [
                'classname' => 'local_ai_manager\external\agent_abort_run',
                'description' => 'Abort a running or awaiting-approval agent run.',
                'type' => 'write',
                'ajax' => true,
                'capabilities' => 'local/ai_manager:use',
        ],
        'local_ai_manager_agent_undo_tool_result' => [
                'classname' => 'local_ai_manager\external\agent_undo_tool_result',
                'description' => 'Undo a reversible tool call within the configured window.',
                'type' => 'write',
                'ajax' => true,
                'capabilities' => 'local/ai_manager:use',
        ],
];

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
 * Upgrade helper functions for aipurpose_agent.
 *
 * @package   aipurpose_agent
 * @copyright 2026 ISB Bayern
 * @author    Thomas Schönlein
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Overwrites the agent prompt setting with the current default value.
 *
 * If the setting contained a customized value, all site admins are notified about the overwrite and
 * receive their previous value, so they can re-apply their customizations. Calling this more than once
 * during the same upgrade run notifies only once, because the setting already contains the default from
 * the second call on.
 */
function aipurpose_agent_reset_agentprompt_to_default(): void {
    $newprompt = \aipurpose_agent\purpose::get_default_agentprompt();
    $oldprompt = get_config('aipurpose_agent', 'agentprompt');
    set_config('agentprompt', $newprompt, 'aipurpose_agent');

    if (empty($oldprompt) || $oldprompt === $newprompt) {
        return;
    }

    // Core registers a plugin's message providers only after all of its upgrade steps have run, so the
    // provider has to be registered explicitly here to be able to send a message within this same run.
    message_update_providers('aipurpose_agent');
    foreach (get_admins() as $admin) {
        $message = new \core\message\message();
        $message->component = 'aipurpose_agent';
        $message->name = 'promptoverwritten';
        $message->courseid = SITEID;
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $admin;
        $message->subject = get_string('promptoverwrittensubject', 'aipurpose_agent');
        $message->fullmessage = get_string('promptoverwrittenmessage', 'aipurpose_agent', $oldprompt);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = get_string('promptoverwrittensubject', 'aipurpose_agent');
        $message->notification = 1;
        message_send($message);
    }
}

/**
 * Forces the popup and email channels to be enabled and locked for the promptoverwritten message provider,
 * so admins can no longer disable this notification.
 */
function aipurpose_agent_force_promptoverwritten_processors() {

    $enabled = get_config('message', 'message_provider_aipurpose_agent_promptoverwritten_enabled');

    $channels = [];
    if ($enabled) {
        $channels = explode(',', $enabled);
    }

    $providers = ['popup', 'email'];
    for ($i = 0; $i < count($providers); $i++) {
        $required = $providers[$i];

        $found = false;
        for ($j = 0; $j < count($channels); $j++) {
            if ($channels[$j] == $required) {
                $found = true;
            }
        }

        if (!$found) {
            $channels[] = $required;
        }
    }

    $providerslist = implode(',', $channels);
    set_config('message_provider_aipurpose_agent_promptoverwritten_enabled', $providerslist, 'message');

    set_config('popup_provider_aipurpose_agent_promptoverwritten_locked', 1, 'message');
    set_config('email_provider_aipurpose_agent_promptoverwritten_locked', 1, 'message');
}

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
 * Tool: forum_create_discussion (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent\tools\forum;

use local_ai_manager\agent\execution_context;
use local_ai_manager\agent\tool_result;
use local_ai_manager\agent\tools\base_tool;

/**
 * Create a new discussion (topic) in a given forum.
 *
 * Backing: mod_forum_external::add_discussion (core web service).
 * Write-tool, requires approval, reversible via forum_delete_discussion.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class forum_create_discussion extends base_tool {

    #[\Override]
    public function get_name(): string {
        return 'forum_create_discussion';
    }

    #[\Override]
    public function get_summary(): string {
        return get_string('tool_forum_create_discussion_summary', 'local_ai_manager');
    }

    #[\Override]
    public function get_description(): string {
        return <<<EOT
Use this tool when the user asks to start a new discussion (topic) in a forum
activity. Typical triggers: "Poste im Ankündigungsforum ...", "Erstelle im
Forum 'Hausaufgaben' einen neuen Beitrag".

Do NOT use this tool to reply to an existing discussion — no reply tool is
available yet. Do NOT use it for forums of type "single" (single-topic
discussion) — these reject new topics and the tool will fail with
forum_type_not_allowed.

Behavior: Requires {forumid, subject, message}. Optional: groupid (0 for "all
participants", -1 to let Moodle pick), pinned, discussionsubscribe,
messageformat (html by default). The tool calls mod_forum_external::add_discussion
internally and returns {discussionid, subject}. Requires explicit approval.

Examples:
  - "Neuer Beitrag im Ankündigungsforum (id 42): 'Klausur verschoben'" ->
    forum_create_discussion({forumid:42, subject:"Klausur verschoben",
                             message:"<p>Die Klausur wird um eine Woche verschoben.</p>"})
EOT;
    }

    #[\Override]
    public function get_category(): string {
        return 'forum';
    }

    #[\Override]
    public function get_keywords(): array {
        return ['forum', 'discussion', 'topic', 'post', 'beitrag', 'ankündigung', 'announcement'];
    }

    #[\Override]
    public function requires_approval(): bool {
        return true;
    }

    #[\Override]
    public function is_idempotent(): bool {
        return false;
    }

    #[\Override]
    public function is_reversible(): bool {
        return true;
    }

    #[\Override]
    public function supports_parallel(): bool {
        return false;
    }

    #[\Override]
    public function get_parameters_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'forumid' => [
                    'type' => 'integer',
                    'description' => 'Numeric id of the forum activity (not cmid).',
                    'minimum' => 1,
                ],
                'subject' => [
                    'type' => 'string',
                    'description' => 'Subject / topic title (1-255 chars, plain text).',
                    'minLength' => 1,
                    'maxLength' => 255,
                ],
                'message' => [
                    'type' => 'string',
                    'description' => 'Message body. HTML is allowed and is cleaned server-side.',
                    'minLength' => 1,
                ],
                'messageformat' => [
                    'type' => 'integer',
                    'description' => 'Moodle text format (1=HTML, 2=PLAIN, 4=MARKDOWN). Defaults to HTML.',
                    'enum' => [FORMAT_HTML, FORMAT_PLAIN, FORMAT_MARKDOWN],
                    'default' => FORMAT_HTML,
                ],
                'groupid' => [
                    'type' => 'integer',
                    'description' => 'Group id. 0 for "all participants"; omit or -1 to let Moodle decide.',
                    'default' => -1,
                ],
                'pinned' => [
                    'type' => 'boolean',
                    'description' => 'Pin the discussion to the top of the list.',
                    'default' => false,
                ],
                'discussionsubscribe' => [
                    'type' => 'boolean',
                    'description' => 'Subscribe the author to the new discussion.',
                    'default' => true,
                ],
            ],
            'required' => ['forumid', 'subject', 'message'],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function get_result_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'discussionid' => ['type' => 'integer'],
                'forumid' => ['type' => 'integer'],
                'subject' => ['type' => 'string'],
            ],
        ];
    }

    #[\Override]
    public function get_affected_objects(array $args): array {
        return [[
            'type' => 'forum',
            'id' => (int) ($args['forumid'] ?? 0),
            'label' => 'forum ' . (int) ($args['forumid'] ?? 0),
        ]];
    }

    #[\Override]
    public function describe_for_user(array $args): string {
        return get_string('tool_forum_create_discussion_describe', 'local_ai_manager', (object) [
            'subject' => (string) ($args['subject'] ?? ''),
            'forumid' => (int) ($args['forumid'] ?? 0),
        ]);
    }

    #[\Override]
    public function build_undo_payload(array $args, tool_result $result): ?array {
        $discussionid = 0;
        if ($result->ok && is_array($result->data) && !empty($result->data['discussionid'])) {
            $discussionid = (int) $result->data['discussionid'];
        }
        return [
            'tool' => 'forum_delete_discussion',
            'args' => ['discussionid' => $discussionid],
            'note' => 'Manual reversal: delete the discussion through the forum UI.',
        ];
    }

    #[\Override]
    protected function run(array $args, execution_context $ctx): tool_result {
        global $CFG, $DB;

        $forumid = (int) $args['forumid'];
        $subject = trim((string) $args['subject']);
        $message = (string) $args['message'];
        $messageformat = (int) ($args['messageformat'] ?? FORMAT_HTML);
        $groupid = (int) ($args['groupid'] ?? -1);
        $pinned = (bool) ($args['pinned'] ?? false);
        $subscribe = (bool) ($args['discussionsubscribe'] ?? true);

        $forum = $DB->get_record('forum', ['id' => $forumid]);
        if (!$forum) {
            return tool_result::failure('forum_not_found',
                get_string('tool_forum_not_found', 'local_ai_manager'));
        }
        if ($forum->type === 'single') {
            return tool_result::failure('forum_type_not_allowed',
                get_string('tool_forum_create_discussion_type_not_allowed', 'local_ai_manager'));
        }

        require_once($CFG->dirroot . '/mod/forum/externallib.php');
        require_once($CFG->dirroot . '/mod/forum/lib.php');

        // The external function reads the current $USER via the session manager.
        // Orchestrator is responsible for running tools as the intended user;
        // we only guard against an obvious mismatch to avoid creating a post
        // on behalf of someone else.
        global $USER;
        if ((int) $USER->id !== (int) $ctx->user->id) {
            return tool_result::failure('user_mismatch',
                'Session user does not match execution context user.');
        }

        try {
            $options = [];
            if ($pinned) {
                $options[] = ['name' => 'discussionpinned', 'value' => true];
            }
            $options[] = ['name' => 'discussionsubscribe', 'value' => $subscribe];

            $result = \mod_forum_external::add_discussion(
                $forumid,
                $subject,
                $message,
                $groupid,
                $options
            );
            // Normalise via the returns schema.
            $result = \core_external\external_api::clean_returnvalue(
                \mod_forum_external::add_discussion_returns(),
                $result
            );
        } catch (\moodle_exception $e) {
            return tool_result::failure($e->errorcode ?: 'forum_add_discussion_failed', $e->getMessage());
        }
        // Align messageformat on the resulting first post if caller asked for non-HTML.
        if ($messageformat !== FORMAT_HTML && !empty($result['discussionid'])) {
            $firstpostid = $DB->get_field('forum_discussions', 'firstpost', ['id' => (int) $result['discussionid']]);
            if ($firstpostid) {
                $DB->set_field('forum_posts', 'messageformat', $messageformat, ['id' => $firstpostid]);
            }
        }
        return tool_result::success([
            'discussionid' => (int) ($result['discussionid'] ?? 0),
            'forumid' => $forumid,
            'subject' => $subject,
        ]);
    }
}

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
 * Tool: module_create (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent\tools\mod;

use local_ai_manager\agent\execution_context;
use local_ai_manager\agent\tool_result;
use local_ai_manager\agent\tools\base_tool;

/**
 * Create a simple activity (page, label, url, forum, assign, quiz, …).
 *
 * Write-tool: requires explicit approval. Uses Moodle's create_module() API
 * which runs the module's full add-instance pipeline (FEATURE_MOD_INTRO is
 * respected automatically).
 *
 * Supported modules in this tool are restricted to those that can be created
 * from a small, well-typed argument set. Exotic options (grading, grouping,
 * completion-per-activity) are left at their Moodle defaults.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class module_create extends base_tool {

    /** @var string[] Module names this tool explicitly supports. */
    private const SUPPORTED_MODNAMES = [
        'page', 'label', 'url', 'resource', 'forum', 'assign', 'quiz', 'folder', 'book',
    ];

    #[\Override]
    public function get_name(): string {
        return 'module_create';
    }

    #[\Override]
    public function get_summary(): string {
        return get_string('tool_module_create_summary', 'local_ai_manager');
    }

    #[\Override]
    public function get_description(): string {
        return <<<EOT
Use this tool to add a new activity or resource (page, label, url, forum, assign,
quiz, folder, book, resource) to a course section. Typical triggers: "Füge ein
Forum hinzu", "Erstelle eine Page mit der Einleitung …".

Do NOT use this tool to update an existing activity (use module_update) or to
add questions to a quiz (use quiz_add_question). Do NOT use it for activity
types outside the supported list — the tool will fail with unsupported_modname.

Behavior: Requires {courseid, section, modname, name}. Optional: intro (HTML),
url (required for modname=url), content (HTML body for page or label),
visible (default true). For quiz and assign only the shell is created with
defaults — configure details afterwards via module_update or the web UI. The
tool requires explicit approval and affects a shared object. It is reversible
by deleting the created course module.

IMPORTANT — where to put the visible body text:
  - modname="label": The label has NO separate name shown to learners. The
    visible body MUST be passed in `content` (or `intro`); `name` is only an
    internal identifier. Do NOT cram the body into `name`.
  - modname="page": The visible body MUST be passed in `content`. `intro`
    becomes the optional short description shown above the page link.
  - modname="forum"/"assign"/"quiz"/"url"/"resource"/"folder"/"book":
    `name` is the activity title shown in the course; `intro` is the
    description.

Examples:
  - "Neues Forum 'Diskussion' in Kurs 42, Abschnitt 1"
    -> module_create({courseid:42, section:1, modname:"forum", name:"Diskussion", intro:"Austausch zum Thema."})
  - "Page 'Willkommen' mit HTML-Inhalt anlegen"
    -> module_create({courseid:42, section:0, modname:"page", name:"Willkommen", content:"<p>Hallo</p>"})
  - "Label mit der Geschichte von Einstein anlegen"
    -> module_create({courseid:42, section:1, modname:"label", name:"Einstein", content:"<p>Albert Einstein wurde …</p>"})
EOT;
    }

    #[\Override]
    public function get_category(): string {
        return 'mod';
    }

    #[\Override]
    public function get_keywords(): array {
        return ['module', 'activity', 'create', 'add', 'neue aktivität', 'erstellen'];
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
    public function get_required_capabilities(): array {
        return [];
    }

    #[\Override]
    public function get_parameters_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'courseid' => [
                    'type' => 'integer',
                    'description' => 'Numeric course id.',
                    'minimum' => 1,
                ],
                'section' => [
                    'type' => 'integer',
                    'description' => 'Section number (0 = general section).',
                    'minimum' => 0,
                ],
                'modname' => [
                    'type' => 'string',
                    'description' => 'Module type. Supported: '
                        . implode(', ', self::SUPPORTED_MODNAMES) . '.',
                    'enum' => self::SUPPORTED_MODNAMES,
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Activity display name (1-250 chars).',
                    'minLength' => 1,
                    'maxLength' => 250,
                ],
                'intro' => [
                    'type' => 'string',
                    'description' => 'Optional activity description/intro (HTML allowed).',
                    'maxLength' => 65535,
                ],
                'url' => [
                    'type' => 'string',
                    'description' => 'External URL. Required when modname="url".',
                    'maxLength' => 2048,
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'HTML content. Used for modname="page".',
                    'maxLength' => 65535,
                ],
                'visible' => [
                    'type' => 'boolean',
                    'description' => 'Whether the new activity is visible to students.',
                    'default' => true,
                ],
            ],
            'required' => ['courseid', 'section', 'modname', 'name'],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function get_result_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'cmid' => ['type' => 'integer'],
                'instanceid' => ['type' => 'integer'],
                'modname' => ['type' => 'string'],
                'url' => ['type' => 'string'],
            ],
        ];
    }

    #[\Override]
    public function get_affected_objects(array $args): array {
        return [[
            'type' => 'course',
            'id' => (int) ($args['courseid'] ?? 0),
            'label' => 'course ' . (int) ($args['courseid'] ?? 0)
                . ' / section ' . (int) ($args['section'] ?? 0),
        ]];
    }

    #[\Override]
    public function describe_for_user(array $args): string {
        return get_string('tool_module_create_describe', 'local_ai_manager', (object) [
            'modname' => (string) ($args['modname'] ?? ''),
            'name' => (string) ($args['name'] ?? ''),
            'courseid' => (int) ($args['courseid'] ?? 0),
            'section' => (int) ($args['section'] ?? 0),
        ]);
    }

    #[\Override]
    public function build_undo_payload(array $args, tool_result $result): ?array {
        if (!$result->ok || !is_array($result->data) || empty($result->data['cmid'])) {
            return null;
        }
        return [
            'tool' => 'module_delete',
            'args' => ['cmid' => (int) $result->data['cmid']],
        ];
    }

    #[\Override]
    protected function run(array $args, execution_context $ctx): tool_result {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');

        $courseid = (int) $args['courseid'];
        $section = (int) $args['section'];
        $modname = (string) $args['modname'];
        $name = trim((string) $args['name']);
        $intro = (string) ($args['intro'] ?? '');
        $url = (string) ($args['url'] ?? '');
        $content = (string) ($args['content'] ?? '');
        $visible = (bool) ($args['visible'] ?? true);

        // Label has no separate display name: the intro IS the body. LLMs
        // frequently misroute the body into `name` or `content`, so we coalesce
        // content -> intro -> name (last-resort) to avoid empty labels.
        if ($modname === 'label') {
            if (trim($intro) === '') {
                if (trim($content) !== '') {
                    $intro = $content;
                } else if ($name !== '') {
                    $intro = $name;
                }
            }
        }

        if (!in_array($modname, self::SUPPORTED_MODNAMES, true)) {
            return tool_result::failure('unsupported_modname',
                get_string('tool_module_create_unsupported_modname', 'local_ai_manager'));
        }

        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            return tool_result::failure('course_not_found',
                get_string('tool_course_not_found', 'local_ai_manager'));
        }
        $coursectx = \core\context\course::instance($courseid);
        require_capability('moodle/course:manageactivities', $coursectx, $ctx->user);

        if ($modname === 'url' && trim($url) === '') {
            return tool_result::failure('missing_url',
                get_string('tool_module_create_url_required', 'local_ai_manager'));
        }

        // Ensure the section exists.
        if (!$DB->record_exists('course_sections', ['course' => $courseid, 'section' => $section])) {
            return tool_result::failure('section_not_found',
                get_string('tool_course_section_not_found', 'local_ai_manager'));
        }

        $data = (object) [
            'modulename' => $modname,
            'course' => $courseid,
            'section' => $section,
            'visible' => $visible ? 1 : 0,
            'name' => $name,
            'introeditor' => [
                'text' => $intro,
                'format' => FORMAT_HTML,
                'itemid' => 0,
            ],
            'showdescription' => 0,
        ];

        switch ($modname) {
            case 'url':
                $data->externalurl = $url;
                $data->display = 0;
                break;
            case 'page':
                $pagetext = $content !== '' ? $content : $intro;
                $data->page = [
                    'text' => $pagetext,
                    'format' => FORMAT_HTML,
                    'itemid' => 0,
                ];
                // page_add_instance() only maps page['text'] -> content when called with an mform.
                // create_module() passes no mform, so we have to set content / contentformat ourselves.
                $data->content = $pagetext;
                $data->contentformat = FORMAT_HTML;
                $data->display = 5;
                $data->printheading = 1;
                $data->printintro = 0;
                $data->printlastmodified = 1;
                break;
            case 'label':
                // Label uses the intro field as its body (already coalesced above).
                $data->introeditor['text'] = $intro;
                break;
            case 'forum':
                $data->type = 'general';
                $data->scale = 0;
                $data->assessed = 0;
                $data->forcesubscribe = 0;
                $data->trackingtype = 0;
                $data->maxbytes = 0;
                $data->maxattachments = 0;
                break;
            case 'assign':
                $data->submissiondrafts = 0;
                $data->sendnotifications = 0;
                $data->sendlatenotifications = 0;
                $data->duedate = 0;
                $data->allowsubmissionsfromdate = 0;
                $data->grade = 100;
                $data->teamsubmission = 0;
                $data->requireallteammemberssubmit = 0;
                $data->blindmarking = 0;
                $data->attemptreopenmethod = 'none';
                $data->maxattempts = -1;
                $data->markingworkflow = 0;
                $data->markingallocation = 0;
                break;
            case 'quiz':
                $data->timeopen = 0;
                $data->timeclose = 0;
                $data->timelimit = 0;
                $data->overduehandling = 'autosubmit';
                $data->graceperiod = 0;
                $data->preferredbehaviour = 'deferredfeedback';
                $data->canredoquestions = 0;
                $data->attempts = 0;
                $data->attemptonlast = 0;
                $data->grademethod = 1;
                $data->decimalpoints = 2;
                $data->questiondecimalpoints = -1;
                $data->reviewattempt = 0x11110;
                $data->reviewcorrectness = 0x11110;
                $data->reviewmarks = 0x11110;
                $data->reviewspecificfeedback = 0x11110;
                $data->reviewgeneralfeedback = 0x11110;
                $data->reviewrightanswer = 0x11110;
                $data->reviewoverallfeedback = 0x11110;
                $data->questionsperpage = 1;
                $data->navmethod = 'free';
                $data->shuffleanswers = 1;
                $data->sumgrades = 0;
                $data->grade = 10;
                $data->password = '';
                $data->subnet = '';
                $data->browsersecurity = '-';
                $data->delay1 = 0;
                $data->delay2 = 0;
                $data->showuserpicture = 0;
                $data->showblocks = 0;
                break;
            // Folder / book / resource fall through with defaults.
        }

        $result = create_module($data);

        $cm = get_coursemodule_from_id('', $result->coursemodule, 0, false, MUST_EXIST);
        $cmurl = new \moodle_url('/mod/' . $modname . '/view.php', ['id' => $cm->id]);

        return tool_result::success(
            data: [
                'cmid' => (int) $cm->id,
                'instanceid' => (int) $cm->instance,
                'modname' => (string) $cm->modname,
                'url' => $cmurl->out(false),
            ],
            affected_objects: [[
                'type' => 'course_module',
                'id' => (int) $cm->id,
                'label' => $modname . ' ' . $name,
            ]],
        );
    }
}

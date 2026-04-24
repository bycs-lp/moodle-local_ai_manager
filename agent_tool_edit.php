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
 * Admin page: edit a single agent tool override (MBS-10761 Baustein 8).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$toolname = required_param('toolname', PARAM_ALPHANUMEXT);

admin_externalpage_setup('local_ai_manager_agent_tools');

$context = \core\context\system::instance();
require_capability('local/ai_manager:managetools', $context);

$registry = new \local_ai_manager\agent\tool_registry();
$tool = $registry->get_by_name($toolname);

$listurl = new \moodle_url('/local/ai_manager/agent_tools.php');
$pageurl = new \moodle_url('/local/ai_manager/agent_tool_edit.php', ['toolname' => $toolname]);
$PAGE->set_url($pageurl);
$PAGE->set_title(get_string('agent_tool_edit_title', 'local_ai_manager', $toolname));
$PAGE->set_heading(get_string('agent_tool_edit_title', 'local_ai_manager', $toolname));

$override = \local_ai_manager\local\agent\entity\tool_override::get_record([
    'toolname' => $toolname,
    'tenantid' => null,
]);

$form = new \local_ai_manager\form\agent_tool_override_form(
    $pageurl->out(false),
    [
        'toolname' => $toolname,
        'default_description' => $tool->get_description(),
        'default_example_appendix' => '',
        'default_glossary_json' => '',
    ],
);
if ($override) {
    $form->set_data([
        'toolname' => $toolname,
        'enabled' => (int) $override->get('enabled'),
        'llm_description_override' => (string) $override->get('llm_description_override'),
        'example_appendix' => (string) $override->get('example_appendix'),
        'glossary_json' => (string) $override->get('glossary_json'),
    ]);
} else {
    $form->set_data(['toolname' => $toolname, 'enabled' => 1]);
}

if ($form->is_cancelled()) {
    redirect($listurl);
}

if ($data = $form->get_data()) {
    if (!$override) {
        $override = new \local_ai_manager\local\agent\entity\tool_override(0, (object) [
            'toolname' => $toolname,
            'tenantid' => null,
        ]);
    }
    $override->set('enabled', (int) !empty($data->enabled));
    $override->set('llm_description_override',
        empty($data->llm_description_override) ? null : (string) $data->llm_description_override);
    $override->set('example_appendix',
        empty($data->example_appendix) ? null : (string) $data->example_appendix);
    $override->set('glossary_json',
        empty($data->glossary_json) ? null : (string) $data->glossary_json);
    if ($override->get('id')) {
        $override->update();
    } else {
        $override->create();
    }
    // Purge the registry cache so new descriptions are picked up on the next request.
    \cache_helper::invalidate_by_definition('local_ai_manager', 'agent_tools');
    redirect($listurl, get_string('agent_tool_override_saved', 'local_ai_manager'),
        null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('agent_tool_edit_title', 'local_ai_manager', $toolname));
echo \html_writer::div($tool->get_summary(), 'alert alert-secondary');

// Show the hardcoded defaults the admin is about to override.
$metarows = [];
$metarows[] = [
    get_string('agent_tool_default_category', 'local_ai_manager'),
    s($tool->get_category()),
];
$metarows[] = [
    get_string('agent_tool_default_requires_approval', 'local_ai_manager'),
    $tool->requires_approval()
        ? get_string('yes')
        : get_string('no'),
];
$keywords = $tool->get_keywords();
if (!empty($keywords)) {
    $metarows[] = [
        get_string('agent_tool_default_keywords', 'local_ai_manager'),
        s(implode(', ', $keywords)),
    ];
}
$capabilities = $tool->get_required_capabilities();
if (!empty($capabilities)) {
    $metarows[] = [
        get_string('agent_tool_default_capabilities', 'local_ai_manager'),
        s(implode(', ', $capabilities)),
    ];
}
$metatable = new \html_table();
$metatable->attributes['class'] = 'generaltable';
$metatable->data = $metarows;

$descriptionblock = \html_writer::tag('pre', s($tool->get_description()), [
    'class' => 'pre-scrollable',
    'style' => 'max-height: 400px; background: #f7f7f7; padding: 1em; border: 1px solid #ddd;',
]);
$schemajson = json_encode($tool->get_parameters_schema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$schemablock = \html_writer::tag('pre', s($schemajson ?: ''), [
    'class' => 'pre-scrollable',
    'style' => 'max-height: 400px; background: #f7f7f7; padding: 1em; border: 1px solid #ddd;',
]);

$defaultsheading = \html_writer::tag('h3', get_string('agent_tool_defaults_heading', 'local_ai_manager'));
$defaultsintro = \html_writer::div(
    get_string('agent_tool_defaults_intro', 'local_ai_manager'),
    'alert alert-info'
);
$defaultsbody = ''
    . \html_writer::tag('h4', get_string('agent_tool_default_metadata', 'local_ai_manager'))
    . \html_writer::table($metatable)
    . \html_writer::tag('h4', get_string('agent_tool_default_description', 'local_ai_manager'))
    . $descriptionblock
    . \html_writer::tag('h4', get_string('agent_tool_default_parameters_schema', 'local_ai_manager'))
    . $schemablock;

echo $defaultsheading;
echo $defaultsintro;
echo \html_writer::div($defaultsbody, 'mb-4');

echo \html_writer::tag('h3', get_string('agent_tool_override_heading', 'local_ai_manager'));
$form->display();
echo $OUTPUT->footer();

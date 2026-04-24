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
 * Admin page: list agent tools and manage overrides (MBS-10761 Baustein 8).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_ai_manager_agent_tools');

$context = \core\context\system::instance();
require_capability('local/ai_manager:managetools', $context);

$PAGE->set_url(new \moodle_url('/local/ai_manager/agent_tools.php'));
$PAGE->set_title(get_string('agent_tools_manage', 'local_ai_manager'));
$PAGE->set_heading(get_string('agent_tools_manage', 'local_ai_manager'));

$registry = new \local_ai_manager\agent\tool_registry();
$tools = $registry->get_all();

$table = new \html_table();
$table->head = [
    get_string('agent_tool_name', 'local_ai_manager'),
    get_string('agent_tool_category', 'local_ai_manager'),
    get_string('agent_tool_requires_approval', 'local_ai_manager'),
    get_string('agent_tool_reversible', 'local_ai_manager'),
    get_string('agent_tool_enabled', 'local_ai_manager'),
    '',
];
$table->attributes['class'] = 'generaltable admintable';

$overridesbyname = [];
foreach (\local_ai_manager\local\agent\entity\tool_override::get_records(['tenantid' => null]) as $row) {
    $overridesbyname[$row->get('toolname')] = $row;
}

foreach ($tools as $tool) {
    $name = $tool->get_name();
    $override = $overridesbyname[$name] ?? null;
    $enabled = $override === null ? true : (bool) $override->get('enabled');
    $editurl = new \moodle_url('/local/ai_manager/agent_tool_edit.php', ['toolname' => $name]);
    $table->data[] = [
        \html_writer::tag('code', $name),
        s($tool->get_category()),
        $tool->requires_approval() ? '✓' : '',
        $tool->is_reversible() ? '✓' : '',
        $enabled
            ? \html_writer::tag('span', get_string('yes'), ['class' => 'badge badge-success'])
            : \html_writer::tag('span', get_string('no'), ['class' => 'badge badge-secondary']),
        \html_writer::link($editurl, get_string('agent_tool_edit', 'local_ai_manager'),
            ['class' => 'btn btn-sm btn-secondary']),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('agent_tools_manage', 'local_ai_manager'));
echo \html_writer::div(get_string('agent_tools_manage_desc', 'local_ai_manager'), 'alert alert-info');
echo \html_writer::table($table);
echo $OUTPUT->footer();

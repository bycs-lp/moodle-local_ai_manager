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
 * Form for editing an agent tool override (MBS-10761 Baustein 8).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Admin form that edits a {@see \local_ai_manager\local\agent\entity\tool_override} row.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class agent_tool_override_form extends \moodleform {

    #[\Override]
    protected function definition() {
        $mform = $this->_form;
        $toolname = (string) ($this->_customdata['toolname'] ?? '');
        $defaultdescription = (string) ($this->_customdata['default_description'] ?? '');
        $defaultexampleappendix = (string) ($this->_customdata['default_example_appendix'] ?? '');
        $defaultglossaryjson = (string) ($this->_customdata['default_glossary_json'] ?? '');

        $mform->addElement('hidden', 'toolname', $toolname);
        $mform->setType('toolname', PARAM_ALPHANUMEXT);

        $mform->addElement('advcheckbox', 'enabled',
            get_string('agent_tool_enabled', 'local_ai_manager'));
        $mform->setDefault('enabled', 1);

        $mform->addElement('textarea', 'llm_description_override',
            get_string('agent_tool_llm_description_override', 'local_ai_manager'),
            [
                'rows' => 14,
                'cols' => 100,
                'placeholder' => $defaultdescription,
                'spellcheck' => 'false',
                'style' => 'font-family: monospace; font-size: 0.85rem;',
            ]);
        $mform->setType('llm_description_override', PARAM_RAW);
        $mform->addHelpButton('llm_description_override', 'agent_tool_llm_description_override',
            'local_ai_manager');

        $mform->addElement('textarea', 'example_appendix',
            get_string('agent_tool_example_appendix', 'local_ai_manager'),
            [
                'rows' => 6,
                'cols' => 100,
                'placeholder' => $defaultexampleappendix !== ''
                    ? $defaultexampleappendix
                    : get_string('agent_tool_example_appendix_placeholder', 'local_ai_manager'),
            ]);
        $mform->setType('example_appendix', PARAM_RAW);
        $mform->addHelpButton('example_appendix', 'agent_tool_example_appendix',
            'local_ai_manager');

        $mform->addElement('textarea', 'glossary_json',
            get_string('agent_tool_glossary_json', 'local_ai_manager'),
            [
                'rows' => 6,
                'cols' => 100,
                'placeholder' => $defaultglossaryjson !== ''
                    ? $defaultglossaryjson
                    : get_string('agent_tool_glossary_json_placeholder', 'local_ai_manager'),
                'spellcheck' => 'false',
                'style' => 'font-family: monospace; font-size: 0.85rem;',
            ]);
        $mform->setType('glossary_json', PARAM_RAW);
        $mform->addHelpButton('glossary_json', 'agent_tool_glossary_json',
            'local_ai_manager');

        $this->add_action_buttons();
    }

    #[\Override]
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if (!empty($data['glossary_json'])) {
            $decoded = json_decode((string) $data['glossary_json'], true);
            if (!is_array($decoded)) {
                $errors['glossary_json'] =
                    get_string('agent_tool_override_invalid_glossary', 'local_ai_manager');
            }
        }
        return $errors;
    }
}

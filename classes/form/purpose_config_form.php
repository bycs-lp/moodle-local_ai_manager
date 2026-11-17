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

namespace local_ai_manager\form;

use local_ai_manager\base_purpose;
use local_ai_manager\local\connector_factory;
use local_ai_manager\local\tenant_factory;
use local_ai_manager\local\userinfo;

defined('MOODLE_INTERNAL') || die;

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Purpose config form.
 *
 * This form enables a tenant manager to select the AI tools for the existing purposes.
 *
 * @package    local_ai_manager
 * @copyright  2024 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purpose_config_form extends \moodleform {
    /**
     * Form definition.
     */
    public function definition() {
        $tenant = \core\di::get(tenant_factory::class)->get();
        $mform = &$this->_form;

        $mform->addElement('hidden', 'tenant', $tenant->get_identifier());
        $mform->setType('tenant', PARAM_TEXT);

        $mform->addElement('header', 'purpose_config_purposes_header', get_string('heading_purposes', 'local_ai_manager'));
        $mform->setExpanded('purpose_config_purposes_header');
        foreach (base_purpose::get_all_purposes(false) as $purpose) {
            $this->add_purpose_elements($purpose);
        }

        // Internal purposes are only used by other purposes or plugins, so they get a separate section with an explanation.
        $internalpurposes = base_purpose::get_all_purposes(true);
        if (!empty($internalpurposes)) {
            $mform->addElement('header', 'purpose_config_internal_header', get_string('internalpurposes', 'local_ai_manager'));
            $mform->setExpanded('purpose_config_internal_header');
            $mform->addElement(
                'html',
                \core\output\html_writer::div(get_string('internalpurposesdescription', 'local_ai_manager'), 'mb-3')
            );
            foreach ($internalpurposes as $purpose) {
                $this->add_purpose_elements($purpose);
            }
        }
        $this->add_action_buttons();
    }

    /**
     * Adds the heading with info icon and the AI tool selects for the user roles of a purpose to the form.
     *
     * @param string $purpose the purpose name
     */
    private function add_purpose_elements(string $purpose): void {
        global $OUTPUT;
        $mform = &$this->_form;

        $factory = \core\di::get(connector_factory::class);
        $instances = $factory::get_connector_instances_for_purpose($purpose);
        $instances = array_map(fn($instance) => $instance->get_name(), $instances);
        $instances[0] = get_string('notselected', 'local_ai_manager');

        $heading = \core\output\html_writer::tag(
            'h4',
            get_string('select_tool_for_purpose', 'local_ai_manager', get_string('pluginname', 'aipurpose_' . $purpose)),
            ['class' => 'h5 mb-0']
        );
        $purposeinfoicon = $OUTPUT->render_from_template('local_ai_manager/purposeinfoicon', ['purposename' => $purpose]);
        $mform->addElement(
            'html',
            \core\output\html_writer::div($heading . $purposeinfoicon, 'd-flex justify-content-between align-items-center mt-4 mb-2')
        );

        $mform->addElement(
            'select',
            base_purpose::get_purpose_tool_config_key($purpose, userinfo::ROLE_BASIC),
            get_string('role_basic', 'local_ai_manager'),
            $instances,
        );
        $mform->setDefault('purpose_' . $purpose . '_tool_role_basic', 0);
        $mform->addElement(
            'select',
            base_purpose::get_purpose_tool_config_key($purpose, userinfo::ROLE_EXTENDED),
            get_string('role_extended', 'local_ai_manager'),
            $instances
        );
        $mform->setDefault('purpose_' . $purpose . '_tool_role_extended', 0);
    }

    /**
     * Ensures that purposes requiring the same model for all user roles get AI tools with the same model.
     *
     * @param array $data the submitted form data
     * @param array $files the uploaded files
     * @return array errors keyed by form element name
     */
    #[\Override]
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $factory = \core\di::get(connector_factory::class);
        foreach (base_purpose::get_all_purposes() as $purpose) {
            if (!$factory->get_purpose_by_purpose_string($purpose)->requires_same_model_for_all_roles()) {
                continue;
            }
            $models = [];
            foreach ([userinfo::ROLE_BASIC, userinfo::ROLE_EXTENDED] as $role) {
                $instanceid = (int) ($data[base_purpose::get_purpose_tool_config_key($purpose, $role)] ?? 0);
                if ($instanceid > 0) {
                    $models[] = $factory->get_connector_instance_by_id($instanceid)->get_model_name();
                }
            }
            if (count(array_unique($models)) > 1) {
                $errors[base_purpose::get_purpose_tool_config_key($purpose, userinfo::ROLE_EXTENDED)] =
                    get_string('formvalidation_purpose_samemodel', 'local_ai_manager');
            }
        }
        return $errors;
    }
}

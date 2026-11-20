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
use local_ai_manager\local\userusage;

defined('MOODLE_INTERNAL') || die;

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Quota config form.
 *
 * This form gathers information for configuring user specific configurations for local_ai_manager.
 *
 * @package    local_ai_manager
 * @copyright  2024 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quota_config_form extends \moodleform {
    /**
     * Form definition.
     */
    public function definition() {
        $tenant = $this->_customdata['tenant'];

        $mform = &$this->_form;

        $mform->addElement('hidden', 'tenant', $tenant);
        $mform->setType('tenant', PARAM_TEXT);

        $mform->addElement(
            'header',
            'general_user_config_settings_header',
            get_string('general_user_settings', 'local_ai_manager')
        );

        $mform->addElement(
            'duration',
            'max_requests_period',
            get_string('max_request_time_window', 'local_ai_manager'),
            ['units' => [HOURSECS, DAYSECS, WEEKSECS], 'defaultunit' => DAYSECS]
        );
        $mform->setType('max_requests_period', PARAM_INT);
        $mform->setDefault('max_requests_period', userusage::MAX_REQUESTS_DEFAULT_PERIOD);

        $mform->addElement('header', 'purposes_config_header', get_string('heading_purposes', 'local_ai_manager'));
        $mform->setExpanded('purposes_config_header');
        foreach (base_purpose::get_all_purposes(false) as $purpose) {
            $this->add_purpose_elements($purpose);
        }

        // Internal purposes are only used by other purposes or plugins, so they get a separate section with an explanation.
        $internalpurposes = base_purpose::get_all_purposes(true);
        if (!empty($internalpurposes)) {
            $mform->addElement('header', 'internal_purposes_config_header', get_string('internalpurposes', 'local_ai_manager'));
            $mform->setExpanded('internal_purposes_config_header');
            $mform->addElement(
                'html',
                \core\output\html_writer::div(get_string('internalpurposesquotadescription', 'local_ai_manager'), 'mb-3')
            );
            foreach ($internalpurposes as $purpose) {
                $this->add_purpose_elements($purpose);
            }
        }

        $this->add_action_buttons();
    }

    /**
     * Adds the heading and the max requests elements for the user roles of a purpose to the form.
     *
     * @param string $purpose the purpose name
     */
    private function add_purpose_elements(string $purpose): void {
        $mform = &$this->_form;

        $mform->addElement(
            'html',
            \core\output\html_writer::tag(
                'h4',
                get_string('max_requests_purpose_heading', 'local_ai_manager', get_string('pluginname', 'aipurpose_' . $purpose)),
                ['class' => 'h5 mt-4 mb-3']
            )
        );
        $roles = [
            'basic' => userusage::MAX_REQUESTS_DEFAULT_ROLE_BASE,
            'extended' => userusage::MAX_REQUESTS_DEFAULT_ROLE_EXTENDED,
        ];
        foreach ($roles as $rolesuffix => $defaultmaxrequests) {
            $limitelement = $purpose . '_limit_requests_' . $rolesuffix;
            $maxrequestselement = $purpose . '_max_requests_' . $rolesuffix;
            $rolename = get_string('role_' . $rolesuffix, 'local_ai_manager');
            $mform->addElement(
                'html',
                \core\output\html_writer::tag('h5', $rolename, ['class' => 'h6 fw-bold mt-3 mb-2'])
            );
            $mform->addElement('selectyesno', $limitelement, get_string('limit_requests', 'local_ai_manager'));
            $mform->setType($limitelement, PARAM_BOOL);
            $mform->setDefault($limitelement, 1);
            $mform->addElement(
                'text',
                $maxrequestselement,
                get_string('max_requests_purpose', 'local_ai_manager'),
                ['size' => 6]
            );
            $mform->setType($maxrequestselement, PARAM_INT);
            $mform->setDefault($maxrequestselement, $defaultmaxrequests);
            $mform->hideIf($maxrequestselement, $limitelement, 'eq', 0);
        }

        if (get_string_manager()->string_exists('limitrequestswarning', 'aipurpose_' . $purpose)) {
            // The warning has to be shown if at least one role is limited. Multiple hideIf conditions on one element are
            // combined with OR, so this cannot be expressed with a single element. Instead, the first element is shown if the
            // basic role is limited and the second one only if the basic role is unlimited, but the extended role is limited.
            $limitbasic = $purpose . '_limit_requests_basic';
            $limitextended = $purpose . '_limit_requests_extended';
            $warning = \core\output\html_writer::div(
                get_string('limitrequestswarning', 'aipurpose_' . $purpose),
                'alert alert-warning mb-0'
            );
            $warningbasic = $purpose . '_limit_requests_warning_basic';
            $mform->addElement('static', $warningbasic, '', $warning);
            $mform->hideIf($warningbasic, $limitbasic, 'eq', 0);
            $warningextended = $purpose . '_limit_requests_warning_extended';
            $mform->addElement('static', $warningextended, '', $warning);
            $mform->hideIf($warningextended, $limitbasic, 'eq', 1);
            $mform->hideIf($warningextended, $limitextended, 'eq', 0);
        }
    }

    /**
     * Some extra validation.
     *
     * @param array $data array of ("fieldname"=>value) of submitted data
     * @param array $files array of uploaded files "element_name"=>tmp_file_path
     * @return array of "element_name"=>"error_description" if there are errors,
     *         or an empty array if everything is OK (true allowed for backwards compatibility too).
     */
    public function validation($data, $files): array {
        $errors = [];
        if (isset($data['max_requests_period']) && intval($data['max_requests_period']) < userusage::MAX_REQUESTS_MIN_PERIOD) {
            $errors['max_requests_period'] = get_string(
                'error_max_requests_period',
                'local_ai_manager',
                format_time(userusage::MAX_REQUESTS_MIN_PERIOD)
            );
        }
        return $errors;
    }
}

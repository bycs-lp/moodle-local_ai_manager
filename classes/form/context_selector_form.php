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

use local_ai_manager\ai_manager_utils;
use local_ai_manager\local\access_manager;

defined('MOODLE_INTERNAL') || die;

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * A form for selecting the correct context.
 *
 * @package    local_ai_manager
 * @copyright  2025 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class context_selector_form extends \moodleform {
    /**
     * Form definition.
     */
    public function definition() {
        global $USER;
        $tenant = \core\di::get(\local_ai_manager\local\tenant::class);
        $mform = &$this->_form;
        $attributes = $mform->getAttributes();
        $attributes['class'] = $attributes['class'] . ' col-md-12';
        $mform->setAttributes($attributes);

        $accessmanager = \core\di::get(access_manager::class);
        $enrolledcourses = enrol_get_all_users_courses($USER->id, false, 'id,shortname', 'shortname ASC');

        $maincontextoptions = [];
        foreach ($enrolledcourses as $course) {
            $coursecontext = \context_course::instance($course->id);
            if (has_capability('local/ai_manager:viewprompts', $coursecontext)) {
                $maincontextoptions[$coursecontext->id] = ai_manager_utils::get_context_displayname($coursecontext, $tenant);
            }
        }

        $maincontextselect =
            $mform->createElement(
                'select',
                'contextid',
                get_string('choosecontext', 'local_ai_manager'),
                [],
                ['onchange' => 'this.form.requestSubmit()']
            );
        foreach ($maincontextoptions as $key => $value) {
            $maincontextselect->addOption($value, $key);
        }

        if ($accessmanager->is_tenant_member() && has_capability('local/ai_manager:viewtenantprompts', $tenant->get_context())) {
            // Add a placeholder.
            $maincontextselect->addOption('', '', ['disabled' => 'disabled']);
            $maincontextselect->addOption(
                ai_manager_utils::get_context_displayname($tenant->get_context(), $tenant),
                $tenant->get_context()->id
            );
        }
        $mform->addElement($maincontextselect);
    }
}

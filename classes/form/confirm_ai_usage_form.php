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

use local_ai_manager\local\userinfo;

defined('MOODLE_INTERNAL') || die;

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Confirmation form for confirming the usage of the AI tools.
 *
 * @package    local_ai_manager
 * @copyright  2025 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class confirm_ai_usage_form extends \moodleform {

    #[\Override]
    public function definition() {
        $mform = &$this->_form;
        $showtermsofuse = &$this->_customdata['showtermsofuse'];

        $confirmtoustring = $showtermsofuse ? get_string('confirmtermsofuse', 'local_ai_manager') :
                get_string('unlockaitools', 'local_ai_manager');

        $mform->addElement('advcheckbox', 'confirmtou', $confirmtoustring);

        $this->add_action_buttons(false, get_string('confirm', 'local_ai_manager'));
    }

    #[\Override]
    public function validation($data, $files): array {
        global $USER;
        $errors = [];
        $userinfo = new userinfo($USER->id);
        if (!$userinfo->is_confirmed()) {
            // The user tries to confirm.
            if (empty($data['confirmtou'])) {
                $errors['confirmtou'] = get_string('error_confirmtermsofuse', 'local_ai_manager');
            }
        }
        return $errors;
    }

}

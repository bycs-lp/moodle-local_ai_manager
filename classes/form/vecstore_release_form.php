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

defined('MOODLE_INTERNAL') || die;

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Confirmation form shown before a vector store instance is deleted or stops being the primary one.
 *
 * Lists the data of other plugins depending on the instance and lets the user decide whether the stored vectors
 * are deleted.
 *
 * Custom data:
 * - vecstorename (string): name of the affected vector store instance
 * - usages (string[][]): usage descriptions indexed by component, see {@see \local_ai_manager\hook\vecstore_usage}
 * - submitlabel (string): label of the confirm button
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class vecstore_release_form extends \moodleform {
    #[\Override]
    public function definition(): void {
        $mform = $this->_form;
        $vecstorename = $this->_customdata['vecstorename'];
        $usages = $this->_customdata['usages'];

        if (empty($usages)) {
            $mform->addElement('static', 'nousage', '',
                get_string('vecstorereleasenousage', 'local_ai_manager', s($vecstorename)));
        } else {
            $mform->addElement('static', 'warning', '',
                get_string('vecstorereleasewarning', 'local_ai_manager', s($vecstorename)));
            foreach ($usages as $component => $descriptions) {
                foreach ($descriptions as $index => $description) {
                    $mform->addElement('static', 'usage_' . $component . '_' . $index,
                        get_string('pluginname', $component), $description);
                }
            }
            $mform->addElement('advcheckbox', 'deletevectors', '',
                get_string('vecstorereleasedeletevectors', 'local_ai_manager'));
        }

        $this->add_action_buttons(true, $this->_customdata['submitlabel']);
    }
}

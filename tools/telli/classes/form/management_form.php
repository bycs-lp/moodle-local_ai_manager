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

namespace aitool_telli\form;

defined('MOODLE_INTERNAL') || die;

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Form for requesting models and usage data from the AIS API.
 *
 * @package    aitool_telli
 * @copyright  2025 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class management_form extends \moodleform {
    #[\Override]
    public function definition() {
        $mform = &$this->_form;

        $mform->addElement('text', 'baseurl', get_string('baseurl', 'aitool_telli'), ['size' => '60']);
        $mform->setType('baseurl', PARAM_URL);
        $baseurl = get_config('aitool_telli', 'baseurl');
        if (!empty($baseurl)) {
            $mform->setDefault('baseurl', $baseurl);
        }

        $mform->addElement('passwordunmask', 'apikey', get_string('apikey', 'aitool_telli'), ['size' => '60']);
        $mform->setType('apikey', PARAM_TEXT);
        $globalapikey = get_config('aitool_telli', 'globalapikey');
        if (!empty($globalapikey)) {
            $mform->setDefault('apikey', $globalapikey);
        }

        $mform->addElement('date_time_selector', 'sincetime', get_string('sincetime', 'aitool_telli'));
        $mform->setDefault('sincetime', time() - YEARSECS); // Default to one week ago.

        $this->add_action_buttons(true, get_string('retrieveinformation', 'aitool_telli'));
    }

    #[\Override]
    public function validation($data, $files): array {
        $errors = [];

        return $errors;
    }
}

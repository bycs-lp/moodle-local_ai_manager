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

namespace aitool_qdrant;

use local_ai_manager\base_instance;

/**
 * Instance class for the connector instance of aitool_googlesynthesize.
 *
 * @package    aitool_qdrant
 * @copyright  University of Strathclyde, 2025
 * @author     Michael Hughes
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class instance extends base_instance {

    #[\Override]
    protected function extend_form_definition(\MoodleQuickForm $mform): void {
//        $mform->setDefault('endpoint', 'https://texttospeech.googleapis.com/v1/text:synthesize');
//        $mform->freeze('endpoint');
    }
}

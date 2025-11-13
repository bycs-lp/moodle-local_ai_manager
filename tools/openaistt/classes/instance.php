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

namespace aitool_openaistt;

use local_ai_manager\base_instance;
use stdClass;

/**
 * Instance class for the connector instance of aitool_openaistt.
 *
 * @package    aitool_openaistt
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class instance extends base_instance {

    #[\Override]
    protected function extend_form_definition(\MoodleQuickForm $mform): void {
        // No additional form fields needed for basic Whisper setup.
        // Azure support could be added here in the future if needed.
    }

    #[\Override]
    protected function get_extended_formdata(): stdClass {
        $data = new stdClass();
        return $data;
    }

    #[\Override]
    protected function extend_store_formdata(stdClass $data): void {
        // Set default endpoint for OpenAI Whisper.
        $endpoint = 'https://api.openai.com/v1/audio/transcriptions';
        $this->set_endpoint($endpoint);
    }
}

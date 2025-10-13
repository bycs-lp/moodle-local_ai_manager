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

namespace aitool_telli;

use local_ai_manager\base_instance;
use local_ai_manager\local\aitool_option_temperature;
use local_ai_manager\local\connector_factory;
use stdClass;

/**
 * Instance class for the connector instance of aitool_telli.
 *
 * @package    aitool_telli
 * @copyright  2025 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class instance extends base_instance {
    #[\Override]
    protected function extend_form_definition(\MoodleQuickForm $mform): void {
        $globalapikey = get_config('aitool_telli', 'globalapikey');
        if (!empty($globalapikey)) {
            $mform->removeElement('apikey');
        }
        $globalendpoint = get_config('aitool_telli', 'baseurl');
        if (!empty($globalendpoint)) {
            $mform->removeElement('endpoint');
        }
        $connectorfactory = \core\di::get(connector_factory::class);
        $connectorinstance = $connectorfactory->get_connector_by_connectorname($this->connector);
        aitool_option_temperature::extend_form_definition($mform, $connectorinstance->get_models_by_purpose()['imggen']);
    }

    #[\Override]
    protected function get_extended_formdata(): stdClass {
        $data = new stdClass();
        $temperature = floatval($this->get_customfield1());
        $temperaturedata = aitool_option_temperature::add_temperature_to_form_data($temperature);
        foreach ($temperaturedata as $key => $value) {
            $data->{$key} = $value;
        }
        return $data;
    }

    #[\Override]
    protected function extend_store_formdata(stdClass $data): void {
        $temperature = aitool_option_temperature::extract_temperature_to_store($data);
        // Necessary to avoid that the endpoint is null which can cause trouble.
        if (empty($data->endpoint)) {
            $this->set_endpoint('');
        }
        $this->set_customfield1($temperature);
    }

    #[\Override]
    protected function extend_validation(array $data, array $files): array {
        $errors = [];
        $errors = array_merge($errors, aitool_option_temperature::validate_temperature($data));
        return $errors;
    }

    /**
     * Getter for the temperature value.
     *
     * @return float the temperature value as float
     */
    public function get_temperature(): float {
        return floatval($this->get_customfield1());
    }
}

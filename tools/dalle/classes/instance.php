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

namespace aitool_dalle;

use local_ai_manager\base_instance;
use local_ai_manager\local\aitool_option_azure;
use stdClass;

/**
 * Instance class for the connector instance of aitool_dalle.
 *
 * @package    aitool_dalle
 * @copyright  2024 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class instance extends base_instance {
    #[\Override]
    protected function extend_form_definition(\MoodleQuickForm $mform): void {
        aitool_option_azure::extend_form_definition($mform);
        $defaultendpoint = 'https://api.openai.com/v1/images/generations';
        $insertat = $mform->elementExists('useglobalapikey') ? 'useglobalapikey' : 'apikey';
        $mform->insertElementBefore(
            $mform->createElement(
                'static',
                'endpointdefault',
                '',
                get_string('endpointhint', 'aitool_dalle')
                . '<br>' . get_string('endpointdefault', 'local_ai_manager', $defaultendpoint)
            ),
            $insertat
        );
        $mform->hideIf('endpointdefault', 'azure_enabled', 'eq', '1');
        $mform->insertElementBefore(
            $mform->createElement(
                'static',
                'endpointexample_azure',
                '',
                get_string('endpointhint_azure', 'aitool_dalle')
                . '<br>' . get_string(
                    'endpointexample',
                    'local_ai_manager',
                    'https://$RESOURCE.openai.azure.com/openai/deployments/'
                    . '$DEPLOYMENT_ID/images/generations?api-version=$API_VERSION'
                )
            ),
            $insertat
        );
        $mform->hideIf('endpointexample_azure', 'azure_enabled', 'neq', '1');
    }

    #[\Override]
    protected function get_extended_formdata(): stdClass {
        $data = new stdClass();
        foreach (aitool_option_azure::add_azure_options_to_form_data($this->get_customfield2()) as $key => $value) {
            $data->{$key} = $value;
        }
        return $data;
    }

    #[\Override]
    protected function extend_store_formdata(stdClass $data): void {
        $enabled = aitool_option_azure::extract_azure_data_to_store($data);
        if ($enabled) {
            $this->set_model(aitool_option_azure::get_azure_model_name($this->get_connector()));
        }
        $this->set_customfield2($enabled);
    }

    #[\Override]
    protected function extend_validation(array $data, array $files): array {
        $errors = [];
        $errors = array_merge($errors, aitool_option_azure::validate_azure_options($data));
        return $errors;
    }

    /**
     * Return if azure is enabled.
     *
     * @return bool true if azure is enabled
     */
    public function azure_enabled(): bool {
        return !empty($this->get_customfield2());
    }
}

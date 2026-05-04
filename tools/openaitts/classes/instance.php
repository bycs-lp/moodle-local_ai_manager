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

namespace aitool_openaitts;

use local_ai_manager\base_instance;
use local_ai_manager\local\aitool_option_azure;
use stdClass;

/**
 * Instance class for the connector instance of aitool_tts.
 *
 * @package    aitool_openaitts
 * @copyright  2024 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class instance extends base_instance {
    #[\Override]
    protected function extend_form_definition(\MoodleQuickForm $mform): void {
        aitool_option_azure::extend_form_definition($mform);
        $endpointdescription = get_string('endpointhint', 'aitool_openaitts')
            . '<br>' . get_string('endpointdefault', 'local_ai_manager', connector::DEFAULT_OPENAI_TTS_ENDPOINT);
        $mform->getElement('endpointdescription')->setValue($endpointdescription);
        $mform->getElement('endpointdescription')->updateAttributes(
            ['class' => 'text-body-secondary small text-break']
        );
        $mform->hideIf('endpointdescription', 'azure_enabled', 'eq', '1');
        $endpointdescriptionazure = $mform->createElement(
            'static',
            'endpointdescription_azure',
            '',
            get_string('endpointhint_azure', 'aitool_openaitts')
            . '<br>' . get_string(
                'endpointexample',
                'local_ai_manager',
                'https://$RESOURCE.openai.azure.com/openai/deployments/$DEPLOYMENT_ID/audio/speech?api-version=$API_VERSION'
            )
        );
        $endpointdescriptionazure->updateAttributes(['class' => 'text-body-secondary small text-break']);
        $mform->insertElementBefore($endpointdescriptionazure, 'endpointdescription');
        $mform->hideIf('endpointdescription_azure', 'azure_enabled', 'neq', '1');
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
        [$enabled] = aitool_option_azure::extract_azure_data_to_store($data);
        $this->set_customfield2($enabled);
    }

    #[\Override]
    protected function extend_validation(array $data, array $files): array {
        return aitool_option_azure::validate_azure_options($data);
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

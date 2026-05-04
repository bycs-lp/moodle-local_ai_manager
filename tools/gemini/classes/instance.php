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

namespace aitool_gemini;

use local_ai_manager\base_instance;
use local_ai_manager\local\aitool_option_temperature;
use local_ai_manager\local\aitool_option_vertexai;
use stdClass;

/**
 * Instance class for the connector instance of aitool_gemini.
 *
 * @package    aitool_gemini
 * @copyright  2024 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class instance extends base_instance {
    /** @var string Constant for declaring that Gemini should be used over the open Google AI API. */
    const GOOGLE_BACKEND_GOOGLEAI = 'googleai';

    /** @var string Constant for declaring that Gemini should be used over Vertex AI. */
    const GOOGLE_BACKEND_VERTEXAI = 'vertexai';

    #[\Override]
    protected function extend_form_definition(\MoodleQuickForm $mform): void {
        $mform->addElement(
            'select',
            'googlebackend',
            get_string('googlebackend', 'aitool_gemini'),
            [
                self::GOOGLE_BACKEND_GOOGLEAI => get_string('googlebackendgoogleai', 'aitool_gemini'),
                self::GOOGLE_BACKEND_VERTEXAI => get_string('googlebackendvertexai', 'aitool_gemini'),
            ]
        );
        $backendelement = $mform->removeElement('googlebackend', false);
        $mform->insertElementBefore($backendelement, 'endpoint');
        aitool_option_vertexai::extend_form_definition($mform);
        $mform->hideIf('serviceaccountjson', 'googlebackend', 'neq', 'vertexai');
        $mform->hideIf('vertexcachestatus', 'googlebackend', 'neq', 'vertexai');
        $mform->hideIf('apikey', 'googlebackend', 'eq', 'vertexai');

        $mform->getElement('endpointdescription')->setValue(
            get_string('endpointhint_googleai', 'aitool_gemini')
            . '<br>' . get_string(
                'endpointexample',
                'local_ai_manager',
                'https://generativelanguage.googleapis.com/v1beta/models/$MODEL:generateContent'
            )
        );
        $mform->getElement('endpointdescription')->updateAttributes(
            ['class' => 'text-body-secondary small text-break']
        );
        $mform->hideIf('endpointdescription', 'googlebackend', 'neq', self::GOOGLE_BACKEND_GOOGLEAI);
        $endpointdescriptionvertexai = $mform->createElement(
            'static',
            'endpointdescription_vertexai',
            '',
            get_string('endpointhint_vertexai', 'aitool_gemini')
            . '<br>' . get_string(
                'endpointexample',
                'local_ai_manager',
                'https://$REGION-aiplatform.googleapis.com/v1/projects/'
                . '$PROJECT_ID/locations/$REGION/publishers/google/models/$MODEL:generateContent'
            )
        );
        $endpointdescriptionvertexai->updateAttributes(['class' => 'text-body-secondary small text-break']);
        $mform->insertElementBefore($endpointdescriptionvertexai, 'endpointdescription');
        $mform->hideIf('endpointdescription_vertexai', 'googlebackend', 'neq', self::GOOGLE_BACKEND_VERTEXAI);

        aitool_option_temperature::extend_form_definition($mform);
    }

    #[\Override]
    protected function get_extended_formdata(): stdClass {
        $data = new stdClass();
        if ($this->get_customfield2() === self::GOOGLE_BACKEND_VERTEXAI) {
            $data->googlebackend = self::GOOGLE_BACKEND_VERTEXAI;
            $vertexaidata = aitool_option_vertexai::add_vertexai_to_form_data($this->get_customfield3());
            foreach ($vertexaidata as $key => $value) {
                $data->{$key} = $value;
            }
        }
        $temperature = $this->get_customfield1();
        $temperaturedata = aitool_option_temperature::add_temperature_to_form_data($temperature);
        foreach ($temperaturedata as $key => $value) {
            $data->{$key} = $value;
        }
        return $data;
    }

    #[\Override]
    protected function extend_store_formdata(stdClass $data): void {
        $temperature = aitool_option_temperature::extract_temperature_to_store($data);
        $this->set_customfield1($temperature);
        $this->set_customfield2($data->googlebackend);
        if ($data->googlebackend === self::GOOGLE_BACKEND_VERTEXAI) {
            [$serviceaccountjson] = aitool_option_vertexai::extract_vertexai_to_store($data);
            $this->set_customfield3($serviceaccountjson);
        }
    }

    #[\Override]
    protected function extend_validation(array $data, array $files): array {
        $errors = [];
        if ($data['googlebackend'] === self::GOOGLE_BACKEND_VERTEXAI) {
            $errors = array_merge($errors, aitool_option_vertexai::validate_vertexai($data));
        }
        $errors = array_merge($errors, aitool_option_temperature::validate_temperature($data));
        // Google endpoint URLs encode the model name (e.g. ".../models/gemini-2.0-flash:generateContent").
        // We require the selected model to appear in the custom URL to prevent mismatches, since the
        // ai_manager architecture depends on knowing the active model at request time.
        if (!empty($data['endpoint']) && !empty($data['model'])) {
            $modelobj = new \local_ai_manager\local\model((int) $data['model']);
            $modelname = $modelobj->get_name();
            if (!empty($modelname) && !str_contains($data['endpoint'], $modelname)) {
                $errors['endpoint'] = get_string('formvalidation_editinstance_endpointmodelnotinurl', 'local_ai_manager');
            }
        }
        return $errors;
    }

    /**
     * Return the current temperature value as float.
     *
     * @return float the current temperature value
     */
    public function get_temperature(): float {
        return floatval($this->get_customfield1());
    }
}

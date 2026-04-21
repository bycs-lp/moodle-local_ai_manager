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

namespace aitool_imagen;

use local_ai_manager\base_instance;
use local_ai_manager\local\aitool_option_vertexai;
use stdClass;

/**
 * Instance class for the connector instance of aitool_imagen.
 *
 * @package    aitool_imagen
 * @copyright  2024 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class instance extends base_instance {
    #[\Override]
    protected function extend_form_definition(\MoodleQuickForm $mform): void {
        aitool_option_vertexai::extend_form_definition($mform);
        // Condition is always true, but there does not seem to be an easy way to always hide an element.
        // Imagen is only accessible via VertexAI using the service account JSON for authentication,
        // so we do not need an API key element here.
        $mform->hideIf('apikey', 'connector', 'imagen');
        $mform->getElement('endpointdescription')->setValue(
            get_string('endpointhint_vertexai', 'aitool_imagen')
            . '<br>' . get_string(
                'endpointexample',
                'local_ai_manager',
                'https://$REGION-aiplatform.googleapis.com/v1/projects/'
                . '$PROJECT_ID/locations/$REGION/publishers/google/models/$MODEL:predict'
            )
        );
        $mform->getElement('endpointdescription')->updateAttributes(
            ['class' => 'text-body-secondary small text-break']
        );
    }

    #[\Override]
    protected function get_extended_formdata(): stdClass {
        return aitool_option_vertexai::add_vertexai_to_form_data($this->get_customfield1());
    }

    #[\Override]
    protected function extend_store_formdata(stdClass $data): void {
        [$serviceaccountjson] = aitool_option_vertexai::extract_vertexai_to_store($data);
        $this->set_customfield1($serviceaccountjson);
    }

    #[\Override]
    protected function extend_validation(array $data, array $files): array {
        $errors = aitool_option_vertexai::validate_vertexai($data);
        // Google endpoint URLs encode the model name (e.g. ".../models/imagen-3.0-generate-002:predict").
        // We require the selected model to appear in the custom URL to prevent mismatches, since the
        // ai_manager architecture depends on knowing the active model at request time.
        if (!empty($data['endpoint']) && !str_contains($data['endpoint'], $data['model'])) {
            $errors['endpoint'] = get_string('formvalidation_editinstance_endpointmodelnotinurl', 'local_ai_manager');
        }
        return $errors;
    }
}

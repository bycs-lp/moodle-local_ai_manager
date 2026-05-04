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

namespace local_ai_manager\local;

use stdClass;

/**
 * Helper class for providing the necessary extension functions to implement the temperature parameter into an ai tool.
 *
 * @package    local_ai_manager
 * @copyright  2024 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class aitool_option_temperature {
    /**
     * Extends the form definition of the edit instance form by adding the temperature option.
     *
     * Temperature fields are automatically hidden for models that do not support the temperature parameter.
     *
     * @param \MoodleQuickForm $mform the mform object
     * @param model[] $selectablemodels array of model objects that are selectable in the form
     */
    public static function extend_form_definition(\MoodleQuickForm $mform, array $selectablemodels = []): void {
        $radioarray = [];
        $radioarray[] = $mform->createElement(
            'radio',
            'temperatureprechoice',
            '',
            get_string('temperature_more_creative', 'local_ai_manager'),
            'selection_creative'
        );
        $radioarray[] = $mform->createElement(
            'radio',
            'temperatureprechoice',
            '',
            get_string('temperature_creative_balanced', 'local_ai_manager'),
            'selection_balanced'
        );
        $radioarray[] = $mform->createElement(
            'radio',
            'temperatureprechoice',
            '',
            get_string('temperature_more_precise', 'local_ai_manager'),
            'selection_precise'
        );
        $mform->addGroup(
            $radioarray,
            'temperatureprechoicearray',
            get_string('temperature_defaultsetting', 'local_ai_manager'),
            ['<br/>'],
            false
        );
        $mform->setDefault('temperatureprechoice', 'selection_balanced');

        $mform->addElement('checkbox', 'temperatureusecustom', get_string('temperature_use_custom_value', 'local_ai_manager'));
        $mform->setDefault('temperatureusecustom', 0);
        $mform->addElement('float', 'temperaturecustom', get_string('temperature_custom_value', 'local_ai_manager'));
        $mform->disabledIf('temperaturecustom', 'temperatureusecustom');
        $mform->disabledIf('temperatureprechoicearray', 'temperatureusecustom', 'checked');

        // Hide temperature fields for models that do not support the temperature parameter.
        foreach ($selectablemodels as $modelobj) {
            if (!$modelobj->supports_temperature()) {
                $modelid = $modelobj->get_id();
                $mform->hideIf('temperatureprechoicearray', 'model', 'eq', $modelid);
                $mform->hideIf('temperatureusecustom', 'model', 'eq', $modelid);
                $mform->hideIf('temperaturecustom', 'model', 'eq', $modelid);
            }
        }
    }

    /**
     * Adds the temperature data to the form data to be passed to the form when loading.
     *
     * If the model does not support temperature, default form values are returned.
     * The prechoice values are dynamically calculated based on the model's temperature range.
     *
     * @param string $temperature the current temperature as read from the database
     * @param model|null $modelobj the model object, if available
     * @return stdClass the object to pass to the form when loading
     */
    public static function add_temperature_to_form_data(string $temperature, ?model $modelobj = null): stdClass {
        $data = new stdClass();

        // If no model is given or the model does not support temperature, return empty object.
        if ($modelobj === null || !$modelobj->supports_temperature()) {
            return $data;
        }

        $data->temperatureusecustom = 0;
        $data->temperatureprechoice = 'selection_balanced';

        $min = 0.0;
        $max = 1.0;
        if ($modelobj->record_exists()) {
            $min = $modelobj->get_min_temperature();
            $max = $modelobj->get_max_temperature();
        }
        $range = $max - $min;

        // Calculate the expected prechoice values based on the model's range.
        $creativetemp = round($min + 0.8 * $range, 1);
        $balancedtemp = round($min + 0.5 * $range, 1);
        $precisetemp = round($min + 0.2 * $range, 1);

        $temperature = floatval($temperature);
        // Use a small epsilon for float comparison.
        $epsilon = 0.0001;
        if (abs($temperature - $creativetemp) < $epsilon) {
            $data->temperatureprechoice = 'selection_creative';
        } else if (abs($temperature - $balancedtemp) < $epsilon) {
            $data->temperatureprechoice = 'selection_balanced';
        } else if (abs($temperature - $precisetemp) < $epsilon) {
            $data->temperatureprechoice = 'selection_precise';
        } else {
            $data->temperatureusecustom = 1;
            $data->temperaturecustom = $temperature;
        }
        return $data;
    }

    /**
     * Extract the temperature from the form data submitted by the form.
     *
     * Returns null if the selected model does not support the temperature parameter.
     * The prechoice values (creative, balanced, precise) are dynamically calculated
     * based on the model's temperature range.
     *
     * @param stdClass $data the form data after submission
     * @return string|null the temperature value in string representation, or null if not supported
     */
    public static function extract_temperature_to_store(stdClass $data): ?string {
        $modelobj = null;
        // Check if the selected model supports temperature.
        if (!empty($data->model)) {
            $modelobj = new model((int) $data->model);
            if ($modelobj->record_exists() && !$modelobj->supports_temperature()) {
                return null;
            }
        }

        $temperature = null;
        if (empty($data->temperatureusecustom)) {
            $min = 0.0;
            $max = 1.0;
            if ($modelobj !== null && $modelobj->record_exists()) {
                $min = $modelobj->get_min_temperature();
                $max = $modelobj->get_max_temperature();
            }
            $range = $max - $min;
            switch ($data->temperatureprechoice) {
                case 'selection_creative':
                    $temperature = number_format($min + 0.8 * $range, 1);
                    break;
                case 'selection_balanced':
                    $temperature = number_format($min + 0.5 * $range, 1);
                    break;
                case 'selection_precise':
                    $temperature = number_format($min + 0.2 * $range, 1);
                    break;
            }
        } else {
            $temperature = trim($data->temperaturecustom);
        }
        return $temperature;
    }

    /**
     * Validation function for the temperature option when form is being submitted.
     *
     * Skips validation if the selected model does not support the temperature parameter.
     * Validates the custom temperature value against the model's allowed range.
     *
     * @param array $data the data being submitted by the form
     * @return array associative array ['mformelementname' => 'error string'] if there are validation errors, otherwise empty array
     */
    public static function validate_temperature(array $data): array {
        // Skip validation if the model does not support temperature.
        $modelobj = new model((int) $data['model']);
        if ($modelobj->record_exists() && !$modelobj->supports_temperature()) {
            return [];
        }

        $errors = [];
        if (!empty($data['temperaturecustom'])) {
            $value = floatval($data['temperaturecustom']);
            $min = $modelobj->get_min_temperature();
            $max = $modelobj->get_max_temperature();
            if ($value < $min || $value > $max) {
                $errors['temperaturecustom'] = get_string(
                    'formvalidation_editinstance_temperaturerange',
                    'local_ai_manager',
                    ['min' => number_format($min, 1), 'max' => number_format($max, 1)]
                );
            }
        }
        return $errors;
    }
}

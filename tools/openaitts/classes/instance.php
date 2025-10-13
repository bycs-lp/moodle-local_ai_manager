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
use local_ai_manager\local\aitool_option_temperature;
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
        aitool_option_azure::extend_form_definition($mform, true);
    }

    #[\Override]
    protected function get_extended_formdata(): stdClass {
        $data = new stdClass();
        foreach (
            aitool_option_azure::add_azure_options_to_form_data(
                $this->get_customfield2(),
                $this->get_customfield3(),
                $this->get_customfield4(),
                $this->get_customfield5()
            ) as $key => $value
        ) {
            $data->{$key} = $value;
        }

        $data->model = self::extract_model_name_from_azure_model_name($this->get_model());
        return $data;
    }

    #[\Override]
    protected function extend_store_formdata(stdClass $data): void {
        [$enabled, $resourcename, $deploymentid, $apiversion] = aitool_option_azure::extract_azure_data_to_store($data);

        if (!empty($enabled)) {
            $endpoint = 'https://' . $resourcename .
                '.openai.azure.com/openai/deployments/'
                . $deploymentid . '/audio/speech?api-version=' . $apiversion;
            // We have an empty model because the model is preconfigured if we're using azure.
            // So we overwrite the default "preconfigured" value by a better model name.
            $this->set_model(self::get_model_specific_azure_model_name($data->model));
        } else {
            $endpoint = 'https://api.openai.com/v1/audio/speech';
        }
        $this->set_endpoint($endpoint);

        $this->set_customfield2($enabled);
        $this->set_customfield3($resourcename);
        $this->set_customfield4($deploymentid);
        $this->set_customfield5($apiversion);
    }

    /**
     * Return if azure is enabled.
     *
     * @return bool true if azure is enabled
     */
    public function azure_enabled(): bool {
        return !empty($this->get_customfield2());
    }

    /**
     * Standardized way of creating a name that is used for storing the model information in case of using Azure.
     *
     * In the case of openaitts we, however, need to also add the real model being used to the name, because we have to distinguish
     * between tts and gpt-4o-mini-tts, for example, because these models do not have the same API options available.
     *
     * @param string $model the model name, for example 'tts' or 'gpt-4o-mini-tts'
     * @return string the calculated model name
     */
    public static function get_model_specific_azure_model_name(string $model): string {
        return aitool_option_azure::get_azure_model_name('openaitts_' . $model);
    }

    /**
     * Extracts the real model name from the azure model name calculated by {@see self::get_model_specific_azure_model_name()}.
     *
     * @param string $azuremodelname the model name when using Azure, for example 'openaitts_tts_preconfigured_azure'
     * @return string the extracted model name, for example 'tts'
     */
    public static function extract_model_name_from_azure_model_name(string $azuremodelname): string {
        return preg_replace('/^openaitts_/', '', aitool_option_azure::get_value_from_azure_model_name($azuremodelname));
    }
}

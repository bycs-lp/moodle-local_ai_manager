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

namespace aitool_openaite;

use local_ai_manager\base_instance;
use local_ai_manager\local\aitool_option_azure;
use local_ai_manager\local\aitool_option_temperature;
use stdClass;

/**
 * Instance class for the connector instance of aitool_openaite.
 *
 * @package    aitool_openaite
 * @copyright  University of Strathclyde 2025,
 * @author     Michael Hughes
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class instance extends \aitool_chatgpt\instance {
    #[\Override]
    protected function extend_store_formdata(stdClass $data): void {

        $temperature = aitool_option_temperature::extract_temperature_to_store($data);
        $this->set_customfield1($temperature);

         [$enabled, $resourcename, $deploymentid, $apiversion] = aitool_option_azure::extract_azure_data_to_store($data);
        if (!empty($enabled)) {
            $endpoint = 'https://' . $resourcename .
                '.openai.azure.com/openai/deployments/'
                . $deploymentid . '/chat/embeddings?api-version=' . $apiversion;
            $this->set_model(aitool_option_azure::get_azure_model_name($this->get_connector()));
        } else {
            $endpoint = 'https://api.openai.com/v1/embeddings';
        }
        
        $this->set_endpoint($endpoint);

        $this->set_customfield2($enabled);
        $this->set_customfield3($resourcename);
        $this->set_customfield4($deploymentid);
        $this->set_customfield5($apiversion);
    }
}
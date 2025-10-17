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

namespace aitool_telli\local;

use core\http_client;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * API handler class for retrieving information from the Telli API.
 *
 * @package   aitool_telli
 * @copyright 2025 ISB Bayern
 * @author    Philipp Memmel
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class apihandler {
    /** @var string The api key to use. */
    private string $apikey = '';

    /** @var string The base url to use. */
    private string $baseurl = '';

    /**
     * Initialize the API connector with the given API key and base URL.
     *
     * @param string $apikey
     * @param string $baseurl
     * @return void
     */
    public function init(string $apikey, string $baseurl): void {
        $this->apikey = $apikey;
        if (!str_ends_with($baseurl, '/')) {
            $baseurl .= '/';
        }
        $this->baseurl = $baseurl;
    }

    /**
     * Helper function to retrieve usage info data from the Telli API.
     *
     * @return string the usage info JSON string or an error message
     */
    public function get_usage_info(): string {
        if (empty($this->apikey) || empty($this->baseurl)) {
            throw new \coding_exception('API key or base URL not set in \aitool_telli\local\apiconnector object.');
        }

        $client = new http_client([
            // We intentionally do not use the global local_ai_manager timeout setting, because here
            // we are not requesting any AI processing, but just query information from the API endpoints.
            'timeout' => 10,
        ]);

        $options['headers'] = $this->get_headers();

        $usageendpoint = $this->baseurl . 'v1/usage';

        try {
            $response = $client->get($usageendpoint, $options);
        } catch (ClientExceptionInterface $exception) {
            throw new \moodle_exception('err_apiresult', 'aitool_telli', '', $exception->getMessage());
        }
        if ($response->getStatusCode() === 200) {
            return $response->getBody()->getContents();
        } else {
            throw new \moodle_exception(
                'err_apiresult',
                'aitool_telli',
                '',
                get_string('statuscode', 'aitool_telli') . ': ' . $response->getStatusCode() . ': ' .
                $response->getReasonPhrase()
            );
        }
    }

    /**
     * Helper function to retrieve model info data from the Telli API.
     *
     * @return string the model info JSON string
     */
    public function get_models_info(): string {
        if (empty($this->apikey) || empty($this->baseurl)) {
            throw new \coding_exception('API key or base URL not set in \aitool_telli\local\apiconnector object.');
        }

        $client = new http_client([
            // We intentionally do not use the global local_ai_manager timeout setting, because here
            // we are not requesting any AI processing, but just query information from the API endpoints.
            'timeout' => 10,
        ]);

        $options['headers'] = $this->get_headers();

        $modelsendpoint = $this->baseurl . 'v1/models';

        try {
            $response = $client->get($modelsendpoint, $options);
        } catch (ClientExceptionInterface $exception) {
            throw new \moodle_exception('err_apiresult', 'aitool_telli', '', $exception->getMessage());
        }
        if ($response->getStatusCode() === 200) {
            return $response->getBody()->getContents();
        } else {
            throw new \moodle_exception(
                'err_apiresult',
                'aitool_telli',
                '',
                get_string('statuscode', 'aitool_telli') . $response->getStatusCode() . ': ' . $response->getReasonPhrase()
            );
        }
    }

    /**
     * Build the headers for API requests.
     *
     * @return array the headers for calling the API endpoints
     */
    public function get_headers(): array {
        return [
            'Authorization' => 'Bearer ' . $this->apikey,
            'Content-Type' => 'application/json;charset=utf-8',
        ];
    }
}

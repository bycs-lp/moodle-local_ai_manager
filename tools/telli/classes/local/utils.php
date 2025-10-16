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
use stdClass;

/**
 * Helper class for the Telli API subplugin.
 *
 * @package    aitool_telli
 * @copyright  2025 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class utils {
    /**
     * @var string|null Mock data for testing purposes
     */
    private static $mockusagedata = null;

    /**
     * Set mock usage data for testing.
     *
     * @param string|null $data Mock data as JSON string or null to disable mocking
     * @return void
     */
    public static function set_mock_usage_data(?string $data): void {
        self::$mockusagedata = $data;
    }

    /**
     * Helper function to retrieve usage info data from the Telli API.
     *
     * @param string $apikey the apikey to use
     * @param string $baseurl the base url for the API
     * @return string
     */
    public static function get_usage_info(string $apikey, string $baseurl): string {
        // Return mock data if we are in a unit test environment and mock data is set.
        if (defined('PHPUNIT_TEST') && PHPUNIT_TEST && self::$mockusagedata !== null) {
            return self::$mockusagedata;
        }

        $client = new http_client([
            // We intentionally do not use the global local_ai_manager timeout setting, because here
            // we are not requesting any AI processing, but just query information from the API endpoints.
            'timeout' => 10,
        ]);

        $options['headers'] = [
            'Authorization' => 'Bearer ' . $apikey,
            'Content-Type' => 'application/json;charset=utf-8',
        ];

        if (!str_ends_with($baseurl, '/')) {
            $baseurl .= '/';
        }

        $usageendpoint = $baseurl . 'v1/usage';

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
     * @param string $apikey the apikey to use
     * @param string $baseurl the base url for the API
     * @return string
     */
    public static function get_models_info(string $apikey, string $baseurl): string {
        $client = new http_client([
            // We intentionally do not use the global local_ai_manager timeout setting, because here
            // we are not requesting any AI processing, but just query information from the API endpoints.
                'timeout' => 10,
        ]);

        $options['headers'] = [
                'Authorization' => 'Bearer ' . $apikey,
                'Content-Type' => 'application/json;charset=utf-8',
        ];

        if (!str_ends_with($baseurl, '/')) {
            $baseurl .= '/';
        }

        $modelsendpoint = $baseurl . 'v1/models';

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
     * Helper function to retrieve usage and model info data from the Telli API.
     *
     * @param string $apikey the apikey to use
     * @param string $baseurl the base url for the API
     * @return stdClass
     */
    public static function get_api_info(string $apikey, string $baseurl): stdClass {
        $return = new stdClass();
        $return->usage = self::get_usage_info($apikey, $baseurl);
        $return->models = self::get_models_info($apikey, $baseurl);
        return $return;
    }
}

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

namespace aitool_telli\task;

use aitool_telli\local\utils;

/**
 * Scheduled task to retrieve and store Telli API consumption data.
 *
 * @package    aitool_telli
 * @copyright  2025 ISB Bayern
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_consumption extends \core\task\scheduled_task {
    /**
     * Epsilon tolerance for float comparison in cents.
     *
     * This prevents false positive reset detection due to floating-point precision issues.
     * A reset is only detected if consumption decreases by more than this value.
     */
    private const EPSILON_TOLERANCE = 0.01;

    /**
     * Get a descriptive name for this task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('getconsumptiontask', 'aitool_telli');
    }

    /**
     * Execute the task.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        $apikey = get_config('aitool_telli', 'globalapikey');
        $baseurl = get_config('aitool_telli', 'baseurl');

        // Skip if configuration is not set.
        if (empty($apikey) || empty($baseurl)) {
            mtrace('Telli API configuration not set. Skipping consumption retrieval.');
            return;
        }

        try {
            // Get usage info from Telli API.
            $aiconnector = \core\di::get(\aitool_telli\local\apihandler::class);
            $aiconnector->init($apikey, $baseurl);
            $usagedata = json_decode($aiconnector->get_usage_info(), true);

            if ($usagedata === null) {
                mtrace('Failed to decode usage data from Telli API.');
                return;
            }

            // Validate required fields.
            if (!isset($usagedata['remainingLimitInCent']) || !isset($usagedata['limitInCent'])) {
                mtrace('Invalid usage data structure from Telli API.');
                return;
            }

            $timecreated = time();
            $limitincent = (float)$usagedata['limitInCent'];
            $remaininglimitincent = (float)$usagedata['remainingLimitInCent'];

            // Calculate current consumption as difference.
            $currentconsumption = $limitincent - $remaininglimitincent;

            // Get the last recorded current consumption value.
            // Sort by id DESC to get the most recent record, even if timecreated is identical.
            $lastrecord = $DB->get_records(
                'aitool_telli_consumption',
                ['type' => 'current'],
                'id DESC',
                '*',
                0,
                1
            );

            $lastvalue = null;
            if (!empty($lastrecord)) {
                $lastrecord = reset($lastrecord);
                $lastvalue = (float)$lastrecord->value;
            }

            // Check if aggregate limit was reset (current consumption is less than last recorded value).
            // Use epsilon for float comparison to avoid false positives due to floating-point precision issues.
            if ($lastvalue !== null && ($lastvalue - $currentconsumption) > self::EPSILON_TOLERANCE) {
                // Store the last value as aggregate before reset.
                $aggregaterecord = new \stdClass();
                $aggregaterecord->type = 'aggregate';
                $aggregaterecord->value = $lastvalue;
                $aggregaterecord->timecreated = $timecreated;
                $DB->insert_record('aitool_telli_consumption', $aggregaterecord);
                mtrace("aggregate limit was reset. Stored previous consumption: {$lastvalue}");
            }

            // Store current consumption value.
            $currentrecord = new \stdClass();
            $currentrecord->type = 'current';
            $currentrecord->value = $currentconsumption;
            $currentrecord->timecreated = $timecreated;
            $DB->insert_record('aitool_telli_consumption', $currentrecord);
            mtrace("Stored current consumption: {$currentconsumption}");

            mtrace('Telli consumption data retrieved and stored successfully.');
        } catch (\moodle_exception $e) {
            mtrace('Error retrieving Telli consumption data: ' . $e->getMessage());
        }
    }
}

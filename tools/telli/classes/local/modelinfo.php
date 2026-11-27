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

/**
 * Model information.
 *
 * @package    aitool_telli
 * @copyright  2026 ISB Bayern
 * @author     Thomas Schönlein
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class modelinfo {
    /** @var int seconds after which the cached model information is fetched again */
    public const CACHE_LIFETIME = DAYSECS;

    /** @var int seconds to wait after a failed fetch before the next attempt */
    public const RETRY_DELAY = 5 * MINSECS;

    /** @var int seconds to wait for the lock held by a concurrent fetch, afterwards the last known state is used */
    public const LOCK_TIMEOUT = 2;

    /**
     * Constructor.
     *
     * @param apihandler $apihandler API handler.
     * @param \core\clock $clock Clock.
     */
    public function __construct(
        /** @var apihandler API handler */
        private readonly apihandler $apihandler,
        /** @var \core\clock time source for the cache lifetime and the retry delay */
        private readonly \core\clock $clock
    ) {
    }

    /**
     * Get the model information.
     *
     * Only one request fetches the model information at a time. Concurrent requests get the last known state.
     *
     * @param string $baseurl The base URL of the API.
     * @param string $apikey The API key.
     * @return array Model information.
     */
    public function get_models(string $baseurl, string $apikey): array {
        $models = [];
        $baseurl = rtrim($baseurl, '/');
        if ($baseurl && $apikey) {
            $cache = \cache::make('aitool_telli', 'modelinfo');
            $cachekey = hash('sha256', $baseurl . $apikey);
            $entry = $cache->get($cachekey);
            $now = $this->clock->time();
            if ($entry) {
                $models = $entry['models'];
                $isfresh = $now - $entry['lastfetched'] < self::CACHE_LIFETIME;
                $inbackoff = $now - $entry['lastfailed'] < self::RETRY_DELAY;
            } else {
                $isfresh = false;
                $inbackoff = false;
            }
            if (!$isfresh && !$inbackoff) {
                $lockfactory = \core\lock\lock_config::get_lock_factory('aitool_telli_modelinfo');
                $lock = $lockfactory->get_lock($cachekey, self::LOCK_TIMEOUT);
                if ($lock) {
                    try {
                        // Another request might have updated the cache while this request was waiting for the lock.
                        $currententry = $cache->get($cachekey);
                        $updatedmeanwhile = $currententry && $currententry !== $entry;
                        if ($updatedmeanwhile) {
                            $models = $currententry['models'];
                        } else {
                            $decoded = null;
                            try {
                                $this->apihandler->init($apikey, $baseurl);
                                $json = $this->apihandler->get_models_info();
                                $decoded = json_decode($json, true);
                            } catch (\moodle_exception $e) {
                                // External API, keep last known state and retry after the retry delay.
                                debugging('Could not retrieve the model information from the telli API: ' . $e->getMessage());
                            }
                            $fetchedmodels = [];
                            if (is_array($decoded)) {
                                foreach ($decoded as $model) {
                                    // External API, skip entries which are no model objects instead of failing the whole request.
                                    if (!is_array($model)) {
                                        continue;
                                    }
                                    $hasname = array_key_exists('name', $model) && is_string($model['name']);
                                    $isdeleted = array_key_exists('isDeleted', $model) && $model['isDeleted'];
                                    if (!$hasname || $isdeleted) {
                                        continue;
                                    }
                                    $fetchedmodels[$model['name']] = $model;
                                }
                            }
                            // External API, a response without any usable model (e.g. "[]" or an error object) counts as
                            // failure too, otherwise a single bad response would remove all models for 24 hours.
                            if (!empty($fetchedmodels)) {
                                $models = $fetchedmodels;
                                $cache->set($cachekey, [
                                    'models' => $models,
                                    'lastfetched' => $now,
                                    'lastfailed' => 0,
                                ]);
                            } else {
                                $lastfetched = $entry['lastfetched'] ?? 0;
                                $cache->set($cachekey, [
                                    'models' => $models,
                                    'lastfetched' => $lastfetched,
                                    'lastfailed' => $now,
                                ]);
                            }
                        }
                    } finally {
                        $lock->release();
                    }
                }
            }
        }
        return $models;
    }

    /**
     * Get the voices for a specific model.
     *
     * @param string $baseurl The base URL of the API.
     * @param string $apikey The API key.
     * @param string $modelname The model name.
     * @return array Voices for the model.
     */
    public function get_voices(string $baseurl, string $apikey, string $modelname): array {
        $models = $this->get_models($baseurl, $apikey);
        $voices = [];
        $modelvoices = $models[$modelname]['modelConfig']['voices'] ?? [];
        foreach ($modelvoices as $voice) {
            if (is_array($voice) && array_key_exists('name', $voice) && array_key_exists('displayName', $voice)) {
                $voices[] = ['key' => $voice['name'], 'displayname' => $voice['displayName']];
            }
        }
        return $voices;
    }
}

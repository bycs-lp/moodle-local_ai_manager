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

use aitool_telli\event\modelinfo_fetch_failed;

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
     * After a failed fetch the last known state is kept and the next attempt is made after the retry delay.
     *
     * @param string $apikey The API key.
     * @param string $baseurl The base URL of the API.
     * @return array Model information.
     */
    public function get_models(string $apikey, string $baseurl): array {
        $baseurl = rtrim($baseurl, '/');
        if (!$baseurl || !$apikey) {
            return [];
        }
        $cache = \cache::make('aitool_telli', 'modelinfo');
        $cachekey = hash('sha256', $baseurl . $apikey);
        $entry = $cache->get($cachekey);
        if ($this->is_valid($entry)) {
            return $entry['models'];
        }
        $lock = \core\lock\lock_config::get_lock_factory('aitool_telli_modelinfo')->get_lock($cachekey, self::LOCK_TIMEOUT);
        if (!$lock) {
            // A concurrent request is fetching, use the last known state.
            return $entry['models'] ?? [];
        }
        try {
            // Another request might have updated the cache while this request was waiting for the lock.
            $entry = $cache->get($cachekey);
            $models = $entry['models'] ?? [];
            if (!$this->is_valid($entry)) {
                $fetchedmodels = $this->fetch_models($apikey, $baseurl);
                $models = $fetchedmodels ?? $models;
                $lifetime = $fetchedmodels === null ? self::RETRY_DELAY : self::CACHE_LIFETIME;
                $cache->set($cachekey, ['models' => $models, 'expires' => $this->clock->time() + $lifetime]);
            }
        } finally {
            $lock->release();
        }
        return $models;
    }

    /**
     * Check whether a cache entry exists and has not expired yet.
     *
     * @param array|false $entry the cache entry, false if there is none
     * @return bool true if the entry can be used without fetching again
     */
    private function is_valid(array|false $entry): bool {
        return $entry && $this->clock->time() < $entry['expires'];
    }

    /**
     * Fetch the model information from the API.
     *
     * Triggers a modelinfo_fetch_failed event if the request fails or the response does not contain any usable model.
     *
     * @param string $apikey The API key.
     * @param string $baseurl The base URL of the API.
     * @return array|null the models indexed by their name, null if fetching failed
     */
    private function fetch_models(string $apikey, string $baseurl): ?array {
        try {
            $this->apihandler->init($apikey, $baseurl);
            $decoded = json_decode($this->apihandler->get_models_info(), true);
        } catch (\moodle_exception $e) {
            // The message is only a generic sentence in the language of the current user, the actual cause
            // (HTTP status or HTTP client message) is passed as $a, so log the error code and $a instead.
            $errormessage = $e->module . '/' . $e->errorcode;
            if (is_string($e->a) && $e->a !== '') {
                $errormessage .= ': ' . $e->a;
            }
            if (debugging('', DEBUG_DEVELOPER)) {
                // Use format_backtrace() instead of getTraceAsString(), the latter may contain the API key
                // as function argument.
                $errormessage .= "\n" . format_backtrace($e->getTrace(), true);
            }
            $this->trigger_fetch_failed($baseurl, $errormessage);
            return null;
        }
        $models = [];
        foreach (is_array($decoded) ? $decoded : [] as $model) {
            // External API, skip entries which are no model objects instead of failing the whole request.
            if (!is_array($model)) {
                continue;
            }
            $hasname = array_key_exists('name', $model) && is_string($model['name']);
            $isdeleted = array_key_exists('isDeleted', $model) && $model['isDeleted'];
            if ($hasname && !$isdeleted) {
                $models[$model['name']] = $model;
            }
        }
        // External API, a response without any usable model (e.g. "[]" or an error object) counts as
        // failure too, otherwise a single bad response would remove all models for 24 hours.
        if (empty($models)) {
            $this->trigger_fetch_failed($baseurl, 'The response does not contain any usable model.');
            return null;
        }
        return $models;
    }

    /**
     * Trigger the event for a failed fetch of the model information.
     *
     * @param string $baseurl The base URL of the API.
     * @param string $errormessage the reason why fetching failed
     */
    private function trigger_fetch_failed(string $baseurl, string $errormessage): void {
        modelinfo_fetch_failed::create([
            'context' => \context_system::instance(),
            'other' => ['baseurl' => $baseurl, 'errormessage' => $errormessage],
        ])->trigger();
    }

    /**
     * Get the voices for a specific model.
     *
     * @param string $apikey The API key.
     * @param string $baseurl The base URL of the API.
     * @param string $modelname The model name.
     * @return array Voices for the model.
     */
    public function get_voices(string $apikey, string $baseurl, string $modelname): array {
        $models = $this->get_models($apikey, $baseurl);
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

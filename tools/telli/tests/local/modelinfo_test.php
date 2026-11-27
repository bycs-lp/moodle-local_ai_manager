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
 * Tests for the Telli model info service.
 *
 * @package    aitool_telli
 * @copyright  2026 ISB Bayern
 * @author     Thomas Schönlein
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\aitool_telli\local\modelinfo::class)]
final class modelinfo_test extends \advanced_testcase {
    /** @var string API key passed to the service in all tests. */
    private const APIKEY = 'phpunitapikey';

    /** @var string Base URL configured in all tests. */
    private const BASEURL = 'https://telli.example.com';

    /** @var string Name of the text to speech model in the fake model info. */
    private const TTSMODEL = 'Azure Speech Text to speech';

    /** @var string Name of the text model in the fake model info. */
    private const TEXTMODEL = 'gpt-4.1';

    /** @var string Name of the image model without model config in the fake model info. */
    private const IMAGEMODEL = 'FLUX.1-Kontext-pro';

    /** @var int Frozen start time of all tests. */
    private const STARTTIME = 1700000000;

    /**
     * Returns the fake response of the Telli endpoint v1/models.
     *
     * @return string the JSON encoded model info
     */
    private static function get_models_response(): string {
        return json_encode([
            [
                'name' => self::TTSMODEL,
                'modelConfig' => [
                    'voices' => [
                        ['name' => 'de-DE-Klaus:MAI', 'displayName' => 'Klaus'],
                        ['name' => 'de-DE-Mia:MAI', 'displayName' => 'Mia'],
                    ],
                ],
                'isDeleted' => false,
            ],
            [
                'name' => self::TEXTMODEL,
                'modelConfig' => null,
                'isDeleted' => false,
            ],
            [
                'name' => self::IMAGEMODEL,
                'modelConfig' => null,
                'isDeleted' => false,
            ],
        ]);
    }

    /**
     * Test that the model info is fetched only once within the cache lifetime.
     */
    public function test_get_models_uses_cache_within_lifetime(): void {
        $this->resetAfterTest();
        $clock = $this->mock_clock_with_frozen(self::STARTTIME);
        $apihandler = $this->createMock(apihandler::class);
        $apihandler->expects($this->once())->method('get_models_info')->willReturn(self::get_models_response());
        \core\di::set(apihandler::class, $apihandler);
        $modelinfo = \core\di::get(modelinfo::class);

        $firstmodels = $modelinfo->get_models(self::BASEURL, self::APIKEY);
        $clock->bump(modelinfo::CACHE_LIFETIME - 1);
        $secondmodels = $modelinfo->get_models(self::BASEURL, self::APIKEY);

        $this->assertSame([self::TTSMODEL, self::TEXTMODEL, self::IMAGEMODEL], array_keys($firstmodels));
        $this->assertSame($firstmodels, $secondmodels);
    }

    /**
     * Test that the model info is fetched again after the cache lifetime and replaces the old models completely.
     */
    public function test_get_models_refetches_after_lifetime(): void {
        $this->resetAfterTest();
        $clock = $this->mock_clock_with_frozen(self::STARTTIME);
        $newresponse = json_encode([['name' => 'gpt-5', 'isDeleted' => false]]);
        $apihandler = $this->createMock(apihandler::class);
        $apihandler->expects($this->exactly(2))->method('get_models_info')
            ->willReturn(self::get_models_response(), $newresponse);
        \core\di::set(apihandler::class, $apihandler);
        $modelinfo = \core\di::get(modelinfo::class);

        $modelinfo->get_models(self::BASEURL, self::APIKEY);
        $clock->bump(modelinfo::CACHE_LIFETIME);
        $models = $modelinfo->get_models(self::BASEURL, self::APIKEY);

        $this->assertSame(['gpt-5'], array_keys($models));
    }

    /**
     * Test that the last known models are kept if the API throws an exception.
     */
    public function test_get_models_keeps_last_state_on_api_exception(): void {
        $this->resetAfterTest();
        $clock = $this->mock_clock_with_frozen(self::STARTTIME);
        $apihandler = $this->createMock(apihandler::class);
        $apihandler->expects($this->exactly(2))->method('get_models_info')
            ->willReturnCallback(function () use ($clock): string {
                // The first request succeeds, the request after the cache lifetime fails.
                if ($clock->time() === self::STARTTIME) {
                    return self::get_models_response();
                }
                throw new \moodle_exception('err_apiresult', 'aitool_telli');
            });
        \core\di::set(apihandler::class, $apihandler);
        $modelinfo = \core\di::get(modelinfo::class);

        $firstmodels = $modelinfo->get_models(self::BASEURL, self::APIKEY);
        $clock->bump(modelinfo::CACHE_LIFETIME);
        $secondmodels = $modelinfo->get_models(self::BASEURL, self::APIKEY);
        $this->assertDebuggingCalled();

        $this->assertSame($firstmodels, $secondmodels);
    }

    /**
     * Data provider for test_get_models_keeps_last_state_on_invalid_response.
     *
     * @return array Datasets with a response body which does not contain any usable model.
     */
    public static function get_models_keeps_last_state_on_invalid_response_provider(): array {
        return [
            'no_json' => ['invalidresponse' => 'Bad Gateway'],
            'json_string' => ['invalidresponse' => '"maintenance"'],
            'empty_body' => ['invalidresponse' => ''],
            'empty_array' => ['invalidresponse' => '[]'],
            'json_object' => ['invalidresponse' => '{"error":"rate limited"}'],
        ];
    }

    /**
     * Test that the last known models are kept if the API returns no usable model.
     *
     * @param string $invalidresponse the response body of the failing request
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('get_models_keeps_last_state_on_invalid_response_provider')]
    public function test_get_models_keeps_last_state_on_invalid_response(string $invalidresponse): void {
        $this->resetAfterTest();
        $clock = $this->mock_clock_with_frozen(self::STARTTIME);
        $apihandler = $this->createMock(apihandler::class);
        $apihandler->expects($this->exactly(2))->method('get_models_info')
            ->willReturn(self::get_models_response(), $invalidresponse);
        \core\di::set(apihandler::class, $apihandler);
        $modelinfo = \core\di::get(modelinfo::class);

        $firstmodels = $modelinfo->get_models(self::BASEURL, self::APIKEY);
        $clock->bump(modelinfo::CACHE_LIFETIME);
        $secondmodels = $modelinfo->get_models(self::BASEURL, self::APIKEY);

        $this->assertSame($firstmodels, $secondmodels);
    }

    /**
     * Test that after a failed request the API is not asked again for 5 minutes.
     */
    public function test_get_models_waits_before_retry_after_failure(): void {
        $this->resetAfterTest();
        $clock = $this->mock_clock_with_frozen(self::STARTTIME);
        $apihandler = $this->createMock(apihandler::class);
        // First call at the start time, the call within the 5 minutes must not reach the API, the call afterwards must.
        $apihandler->expects($this->exactly(2))->method('get_models_info')
            ->willThrowException(new \moodle_exception('err_apiresult', 'aitool_telli'));
        \core\di::set(apihandler::class, $apihandler);
        $modelinfo = \core\di::get(modelinfo::class);

        $modelsafterfailure = $modelinfo->get_models(self::BASEURL, self::APIKEY);
        $this->assertDebuggingCalled();
        $clock->bump(modelinfo::RETRY_DELAY - 1);
        $modelswithinretrydelay = $modelinfo->get_models(self::BASEURL, self::APIKEY);
        $clock->bump(1);
        $modelsafterretrydelay = $modelinfo->get_models(self::BASEURL, self::APIKEY);
        $this->assertDebuggingCalled();

        $this->assertSame([], $modelsafterfailure);
        $this->assertSame([], $modelswithinretrydelay);
        $this->assertSame([], $modelsafterretrydelay);
    }

    /**
     * Test that different base URLs and API keys do not share the cached model info.
     */
    public function test_get_models_caches_per_baseurl_and_apikey(): void {
        $this->resetAfterTest();
        $this->mock_clock_with_frozen(self::STARTTIME);
        $apihandler = $this->createMock(apihandler::class);
        // One request per combination of base URL and API key, the repeated first combination comes from the cache.
        $apihandler->expects($this->exactly(3))->method('get_models_info')->willReturn(self::get_models_response());
        \core\di::set(apihandler::class, $apihandler);
        $modelinfo = \core\di::get(modelinfo::class);

        $modelinfo->get_models(self::BASEURL, self::APIKEY);
        $modelinfo->get_models(self::BASEURL, 'otherapikey');
        $modelinfo->get_models('https://telli-staging.example.com', self::APIKEY);
        $models = $modelinfo->get_models(self::BASEURL, self::APIKEY);

        $this->assertSame([self::TTSMODEL, self::TEXTMODEL, self::IMAGEMODEL], array_keys($models));
    }

    /**
     * Test that deleted models, models without a valid name and entries which are no objects are not stored.
     */
    public function test_get_models_skips_deleted_and_unnamed_models(): void {
        $this->resetAfterTest();
        $this->mock_clock_with_frozen(self::STARTTIME);
        $response = json_encode([
            ['name' => self::TEXTMODEL, 'isDeleted' => false],
            ['name' => 'gemini-3.1-flash-tts-preview', 'isDeleted' => true],
            ['displayName' => 'Model without name'],
            ['name' => 42],
            'not a model',
        ]);
        $apihandler = $this->createMock(apihandler::class);
        $apihandler->expects($this->once())->method('get_models_info')->willReturn($response);
        \core\di::set(apihandler::class, $apihandler);
        $modelinfo = \core\di::get(modelinfo::class);

        $models = $modelinfo->get_models(self::BASEURL, self::APIKEY);

        $this->assertSame([self::TEXTMODEL], array_keys($models));
    }

    /**
     * Data provider for test_get_models_without_configuration.
     *
     * @return array
     */
    public static function get_models_without_configuration_provider(): array {
        return [
            'empty base URL' => [
                'baseurl' => '',
                'apikey' => self::APIKEY,
            ],
            'base URL only consisting of a slash' => [
                'baseurl' => '/',
                'apikey' => self::APIKEY,
            ],
            'empty API key' => [
                'baseurl' => self::BASEURL,
                'apikey' => '',
            ],
        ];
    }

    /**
     * Test that no request is sent, nothing is cached and no models are returned without API key or base URL.
     *
     * A missing configuration is no failure of the external API, so it must not trigger the retry delay.
     *
     * @param string $baseurl the base URL
     * @param string $apikey the API key
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('get_models_without_configuration_provider')]
    public function test_get_models_without_configuration(string $baseurl, string $apikey): void {
        $this->resetAfterTest();
        $this->mock_clock_with_frozen(self::STARTTIME);
        $apihandler = $this->createMock(apihandler::class);
        $apihandler->expects($this->never())->method('init');
        $apihandler->expects($this->never())->method('get_models_info');
        \core\di::set(apihandler::class, $apihandler);
        $modelinfo = \core\di::get(modelinfo::class);

        $models = $modelinfo->get_models($baseurl, $apikey);

        $this->assertSame([], $models);
        $cachekey = hash('sha256', rtrim($baseurl, '/') . $apikey);
        $this->assertFalse(\cache::make('aitool_telli', 'modelinfo')->get($cachekey));
    }

    /**
     * Test that a trailing slash of the base URL does not lead to a separate cache entry.
     */
    public function test_get_models_ignores_trailing_slash_of_baseurl(): void {
        $this->resetAfterTest();
        $this->mock_clock_with_frozen(self::STARTTIME);
        $apihandler = $this->createMock(apihandler::class);
        $apihandler->expects($this->once())->method('get_models_info')->willReturn(self::get_models_response());
        \core\di::set(apihandler::class, $apihandler);
        $modelinfo = \core\di::get(modelinfo::class);

        $modelinfo->get_models(self::BASEURL, self::APIKEY);
        $models = $modelinfo->get_models(self::BASEURL . '/', self::APIKEY);

        $this->assertSame([self::TTSMODEL, self::TEXTMODEL, self::IMAGEMODEL], array_keys($models));
    }

    /**
     * Data provider for test_get_voices.
     *
     * @return array Datasets with model name and expected voices.
     */
    public static function get_voices_provider(): array {
        return [
            'tts_model' => [
                'modelname' => self::TTSMODEL,
                'expected' => [
                    ['key' => 'de-DE-Klaus:MAI', 'displayname' => 'Klaus'],
                    ['key' => 'de-DE-Mia:MAI', 'displayname' => 'Mia'],
                ],
            ],
            'model_config_null' => [
                'modelname' => self::TEXTMODEL,
                'expected' => [],
            ],
            'unknown_model' => [
                'modelname' => 'unknown-model',
                'expected' => [],
            ],
        ];
    }

    /**
     * Test that the voices of a model are mapped to the option format of the TTS purpose.
     *
     * @param string $modelname the name of the model to get the voices for
     * @param array $expected the expected voices
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('get_voices_provider')]
    public function test_get_voices(string $modelname, array $expected): void {
        $this->resetAfterTest();
        $this->mock_clock_with_frozen(self::STARTTIME);
        $apihandler = $this->createMock(apihandler::class);
        $apihandler->method('get_models_info')->willReturn(self::get_models_response());
        \core\di::set(apihandler::class, $apihandler);
        $modelinfo = \core\di::get(modelinfo::class);

        $voices = $modelinfo->get_voices(self::BASEURL, self::APIKEY, $modelname);

        $this->assertSame($expected, $voices);
    }

    /**
     * Test that a request does not fetch the model info while another request holds the lock, but gets the last known state.
     */
    public function test_get_models_returns_last_state_while_locked(): void {
        global $CFG;
        $this->resetAfterTest();
        // The database lock factories allow a second lock on the same resource within the same database session,
        // file locks via flock() do not, so the concurrent request can be simulated in one process.
        $CFG->lock_factory = '\\core\\lock\\file_lock_factory';
        $clock = $this->mock_clock_with_frozen(self::STARTTIME);
        $apihandler = $this->createMock(apihandler::class);
        $apihandler->expects($this->once())->method('get_models_info')->willReturn(self::get_models_response());
        \core\di::set(apihandler::class, $apihandler);
        $modelinfo = \core\di::get(modelinfo::class);
        $firstmodels = $modelinfo->get_models(self::BASEURL, self::APIKEY);
        $clock->bump(modelinfo::CACHE_LIFETIME);
        $lockfactory = \core\lock\lock_config::get_lock_factory('aitool_telli_modelinfo');
        $lock = $lockfactory->get_lock(hash('sha256', self::BASEURL . self::APIKEY), 0);
        $this->assertNotFalse($lock);

        try {
            $secondmodels = $modelinfo->get_models(self::BASEURL, self::APIKEY);
        } finally {
            $lock->release();
        }

        $this->assertSame($firstmodels, $secondmodels);
    }

    /**
     * Test that the lock is released if fetching the model info fails, so the next attempt is not blocked.
     */
    public function test_get_models_releases_lock_after_failure(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->lock_factory = '\\core\\lock\\file_lock_factory';
        $this->mock_clock_with_frozen(self::STARTTIME);
        $apihandler = $this->createMock(apihandler::class);
        $apihandler->expects($this->once())->method('get_models_info')
            ->willThrowException(new \moodle_exception('err_apiresult', 'aitool_telli'));
        \core\di::set(apihandler::class, $apihandler);
        $modelinfo = \core\di::get(modelinfo::class);

        $models = $modelinfo->get_models(self::BASEURL, self::APIKEY);
        $this->assertDebuggingCalled();
        $lockfactory = \core\lock\lock_config::get_lock_factory('aitool_telli_modelinfo');
        $lock = $lockfactory->get_lock(hash('sha256', self::BASEURL . self::APIKEY), 0);
        $lockreleased = $lock !== false;
        if ($lockreleased) {
            $lock->release();
        }

        $this->assertSame([], $models);
        $this->assertTrue($lockreleased);
    }

    /**
     * Test that a request which got the lock does not fetch again if a concurrent request updated the cache meanwhile.
     */
    public function test_get_models_uses_cache_updated_while_waiting_for_lock(): void {
        global $CFG;
        require_once(__DIR__ . '/../fixtures/callback_lock_factory.php');
        $this->resetAfterTest();
        $CFG->lock_factory = '\\aitool_telli\\fixtures\\callback_lock_factory';
        $clock = $this->mock_clock_with_frozen(self::STARTTIME);
        $apihandler = $this->createMock(apihandler::class);
        $apihandler->expects($this->never())->method('get_models_info');
        \core\di::set(apihandler::class, $apihandler);
        $modelinfo = \core\di::get(modelinfo::class);
        $concurrentmodels = ['gpt-5' => ['name' => 'gpt-5', 'isDeleted' => false]];
        \aitool_telli\fixtures\callback_lock_factory::$beforelock = function () use ($concurrentmodels, $clock): void {
            $cache = \cache::make('aitool_telli', 'modelinfo');
            $cache->set(hash('sha256', self::BASEURL . self::APIKEY), [
                'models' => $concurrentmodels,
                'lastfetched' => $clock->time(),
                'lastfailed' => 0,
            ]);
        };

        try {
            $models = $modelinfo->get_models(self::BASEURL, self::APIKEY);
        } finally {
            \aitool_telli\fixtures\callback_lock_factory::$beforelock = null;
        }

        $this->assertSame($concurrentmodels, $models);
    }

    /**
     * Test that a request which got the lock reads the cache store and not its own outdated copy.
     *
     * The concurrent request writes through a new cache instance, so the static acceleration array of the
     * cache instance used by get_models() still holds the outdated entry, as it would in a separate process.
     */
    public function test_get_models_uses_store_updated_by_other_request_while_waiting_for_lock(): void {
        global $CFG;
        require_once(__DIR__ . '/../fixtures/callback_lock_factory.php');
        $this->resetAfterTest();
        $CFG->lock_factory = '\\aitool_telli\\fixtures\\callback_lock_factory';
        $clock = $this->mock_clock_with_frozen(self::STARTTIME);
        $apihandler = $this->createMock(apihandler::class);
        $apihandler->expects($this->once())->method('get_models_info')->willReturn(self::get_models_response());
        \core\di::set(apihandler::class, $apihandler);
        $modelinfo = \core\di::get(modelinfo::class);
        $modelinfo->get_models(self::BASEURL, self::APIKEY);
        $clock->bump(modelinfo::CACHE_LIFETIME);
        $concurrentmodels = ['gpt-5' => ['name' => 'gpt-5', 'isDeleted' => false]];
        \aitool_telli\fixtures\callback_lock_factory::$beforelock = function () use ($concurrentmodels, $clock): void {
            \cache_factory::reset();
            $othercache = \cache::make('aitool_telli', 'modelinfo');
            $othercache->set(hash('sha256', self::BASEURL . self::APIKEY), [
                'models' => $concurrentmodels,
                'lastfetched' => $clock->time(),
                'lastfailed' => 0,
            ]);
        };

        try {
            $models = $modelinfo->get_models(self::BASEURL, self::APIKEY);
        } finally {
            \aitool_telli\fixtures\callback_lock_factory::$beforelock = null;
        }

        $this->assertSame($concurrentmodels, $models);
    }
}

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
        set_config('baseurl', self::BASEURL, 'aitool_telli');
        $clock = $this->mock_clock_with_frozen(self::STARTTIME);
        $apihandler = $this->createMock(apihandler::class);
        $apihandler->expects($this->once())->method('get_models_info')->willReturn(self::get_models_response());
        $modelinfo = new modelinfo($apihandler, $clock);

        $firstmodels = $modelinfo->get_models(self::APIKEY);
        $clock->bump(DAYSECS - 1);
        $secondmodels = $modelinfo->get_models(self::APIKEY);

        $this->assertSame([self::TTSMODEL, self::TEXTMODEL, self::IMAGEMODEL], array_keys($firstmodels));
        $this->assertSame($firstmodels, $secondmodels);
    }

    /**
     * Test that the model info is fetched again after the cache lifetime and replaces the old models completely.
     */
    public function test_get_models_refetches_after_lifetime(): void {
        $this->resetAfterTest();
        set_config('baseurl', self::BASEURL, 'aitool_telli');
        $clock = $this->mock_clock_with_frozen(self::STARTTIME);
        $newresponse = json_encode([['name' => 'gpt-5', 'isDeleted' => false]]);
        $apihandler = $this->createMock(apihandler::class);
        $apihandler->expects($this->exactly(2))->method('get_models_info')
            ->willReturn(self::get_models_response(), $newresponse);
        $modelinfo = new modelinfo($apihandler, $clock);

        $modelinfo->get_models(self::APIKEY);
        $clock->bump(DAYSECS);
        $models = $modelinfo->get_models(self::APIKEY);

        $this->assertSame(['gpt-5'], array_keys($models));
    }

    /**
     * Test that the last known models are kept if the API throws an exception.
     */
    public function test_get_models_keeps_last_state_on_api_exception(): void {
        $this->resetAfterTest();
        set_config('baseurl', self::BASEURL, 'aitool_telli');
        $clock = $this->mock_clock_with_frozen(self::STARTTIME);
        $workingapihandler = $this->createMock(apihandler::class);
        $workingapihandler->expects($this->once())->method('get_models_info')->willReturn(self::get_models_response());
        $failingapihandler = $this->createMock(apihandler::class);
        $failingapihandler->expects($this->once())->method('get_models_info')
            ->willThrowException(new \moodle_exception('err_apiresult', 'aitool_telli'));

        $firstmodels = (new modelinfo($workingapihandler, $clock))->get_models(self::APIKEY);
        $clock->bump(DAYSECS);
        $secondmodels = (new modelinfo($failingapihandler, $clock))->get_models(self::APIKEY);

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
        set_config('baseurl', self::BASEURL, 'aitool_telli');
        $clock = $this->mock_clock_with_frozen(self::STARTTIME);
        $apihandler = $this->createMock(apihandler::class);
        $apihandler->expects($this->exactly(2))->method('get_models_info')
            ->willReturn(self::get_models_response(), $invalidresponse);
        $modelinfo = new modelinfo($apihandler, $clock);

        $firstmodels = $modelinfo->get_models(self::APIKEY);
        $clock->bump(DAYSECS);
        $secondmodels = $modelinfo->get_models(self::APIKEY);

        $this->assertSame($firstmodels, $secondmodels);
    }

    /**
     * Test that after a failed request the API is not asked again for 5 minutes.
     */
    public function test_get_models_waits_before_retry_after_failure(): void {
        $this->resetAfterTest();
        set_config('baseurl', self::BASEURL, 'aitool_telli');
        $clock = $this->mock_clock_with_frozen(self::STARTTIME);
        $apihandler = $this->createMock(apihandler::class);
        // First call at the start time, the call within the 5 minutes must not reach the API, the call afterwards must.
        $apihandler->expects($this->exactly(2))->method('get_models_info')
            ->willThrowException(new \moodle_exception('err_apiresult', 'aitool_telli'));
        $modelinfo = new modelinfo($apihandler, $clock);

        $modelsafterfailure = $modelinfo->get_models(self::APIKEY);
        $clock->bump(5 * MINSECS - 1);
        $modelswithinretrydelay = $modelinfo->get_models(self::APIKEY);
        $clock->bump(1);
        $modelsafterretrydelay = $modelinfo->get_models(self::APIKEY);

        $this->assertSame([], $modelsafterfailure);
        $this->assertSame([], $modelswithinretrydelay);
        $this->assertSame([], $modelsafterretrydelay);
    }

    /**
     * Test that different base URLs and API keys do not share the cached model info.
     */
    public function test_get_models_caches_per_baseurl_and_apikey(): void {
        $this->resetAfterTest();
        set_config('baseurl', self::BASEURL, 'aitool_telli');
        $clock = $this->mock_clock_with_frozen(self::STARTTIME);
        $apihandler = $this->createMock(apihandler::class);
        // One request per combination of base URL and API key, the repeated first combination comes from the cache.
        $apihandler->expects($this->exactly(3))->method('get_models_info')->willReturn(self::get_models_response());
        $modelinfo = new modelinfo($apihandler, $clock);

        $modelinfo->get_models(self::APIKEY);
        $modelinfo->get_models('otherapikey');
        set_config('baseurl', 'https://telli-staging.example.com', 'aitool_telli');
        $modelinfo->get_models(self::APIKEY);
        set_config('baseurl', self::BASEURL, 'aitool_telli');
        $models = $modelinfo->get_models(self::APIKEY);

        $this->assertSame([self::TTSMODEL, self::TEXTMODEL, self::IMAGEMODEL], array_keys($models));
    }

    /**
     * Test that deleted models, models without a valid name and entries which are no objects are not stored.
     */
    public function test_get_models_skips_deleted_and_unnamed_models(): void {
        $this->resetAfterTest();
        set_config('baseurl', self::BASEURL, 'aitool_telli');
        $clock = $this->mock_clock_with_frozen(self::STARTTIME);
        $response = json_encode([
            ['name' => self::TEXTMODEL, 'isDeleted' => false],
            ['name' => 'gemini-3.1-flash-tts-preview', 'isDeleted' => true],
            ['displayName' => 'Model without name'],
            ['name' => 42],
            'not a model',
        ]);
        $apihandler = $this->createMock(apihandler::class);
        $apihandler->expects($this->once())->method('get_models_info')->willReturn($response);
        $modelinfo = new modelinfo($apihandler, $clock);

        $models = $modelinfo->get_models(self::APIKEY);

        $this->assertSame([self::TEXTMODEL], array_keys($models));
    }

    /**
     * Test that no request is sent and no models are returned without a configured base URL.
     */
    public function test_get_models_without_baseurl(): void {
        $this->resetAfterTest();
        $clock = $this->mock_clock_with_frozen(self::STARTTIME);
        $apihandler = $this->createMock(apihandler::class);
        $apihandler->expects($this->never())->method('get_models_info');
        $modelinfo = new modelinfo($apihandler, $clock);

        $models = $modelinfo->get_models(self::APIKEY);

        $this->assertSame([], $models);
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
        set_config('baseurl', self::BASEURL, 'aitool_telli');
        $clock = $this->mock_clock_with_frozen(self::STARTTIME);
        $apihandler = $this->createMock(apihandler::class);
        $apihandler->method('get_models_info')->willReturn(self::get_models_response());
        $modelinfo = new modelinfo($apihandler, $clock);

        $voices = $modelinfo->get_voices(self::APIKEY, $modelname);

        $this->assertSame($expected, $voices);
    }
}

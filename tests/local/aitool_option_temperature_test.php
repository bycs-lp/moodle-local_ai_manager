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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

/**
 * Tests for the temperature option helper.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\local_ai_manager\local\aitool_option_temperature::class)]
final class aitool_option_temperature_test extends \advanced_testcase {
    /**
     * Helper to create a model with a given temperature range via the generator.
     *
     * @param string $name model name
     * @param ?string $temperature temperature range string (e.g. "0.0-2.0") or null
     * @return model the model object
     */
    private function create_model(string $name, ?string $temperature): model {
        /** @var \local_ai_manager_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_ai_manager');
        $record = $generator->create_model([
            'name' => $name,
            'temperature' => $temperature,
        ]);
        return new model((int) $record->id);
    }

    /**
     * Test extract_temperature_to_store with default range model (0.0-1.0).
     */
    public function test_extract_temperature_default_range(): void {
        $this->resetAfterTest();

        $modelobj = $this->create_model('test-default-range', '0.0-1.0');

        // Test creative prechoice.
        $data = new stdClass();
        $data->model = $modelobj->get_id();
        $data->temperatureusecustom = 0;
        $data->temperatureprechoice = 'selection_creative';
        $data->temperaturecustom = '';
        $result = aitool_option_temperature::extract_temperature_to_store($data);
        $this->assertSame('0.8', $result);

        // Test balanced prechoice.
        $data->temperatureprechoice = 'selection_balanced';
        $result = aitool_option_temperature::extract_temperature_to_store($data);
        $this->assertSame('0.5', $result);

        // Test precise prechoice.
        $data->temperatureprechoice = 'selection_precise';
        $result = aitool_option_temperature::extract_temperature_to_store($data);
        $this->assertSame('0.2', $result);
    }

    /**
     * Test extract_temperature_to_store with wide range model (0.0-2.0).
     */
    public function test_extract_temperature_wide_range(): void {
        $this->resetAfterTest();

        $modelobj = $this->create_model('test-wide-range', '0.0-2.0');

        $data = new stdClass();
        $data->model = $modelobj->get_id();
        $data->temperatureusecustom = 0;
        $data->temperaturecustom = '';

        // Creative: 0.0 + 0.8 * 2.0 = 1.6.
        $data->temperatureprechoice = 'selection_creative';
        $result = aitool_option_temperature::extract_temperature_to_store($data);
        $this->assertSame('1.6', $result);

        // Balanced: 0.0 + 0.5 * 2.0 = 1.0.
        $data->temperatureprechoice = 'selection_balanced';
        $result = aitool_option_temperature::extract_temperature_to_store($data);
        $this->assertSame('1.0', $result);

        // Precise: 0.0 + 0.2 * 2.0 = 0.4.
        $data->temperatureprechoice = 'selection_precise';
        $result = aitool_option_temperature::extract_temperature_to_store($data);
        $this->assertSame('0.4', $result);
    }

    /**
     * Test extract_temperature_to_store with custom value.
     */
    public function test_extract_temperature_custom_value(): void {
        $this->resetAfterTest();

        $modelobj = $this->create_model('custom-model', '0.0-1.0');

        $data = new stdClass();
        $data->model = $modelobj->get_id();
        $data->temperatureusecustom = 1;
        $data->temperatureprechoice = 'selection_balanced';
        $data->temperaturecustom = '0.7';

        $result = aitool_option_temperature::extract_temperature_to_store($data);
        $this->assertSame('0.7', $result);
    }

    /**
     * Test extract_temperature_to_store returns null for model without temperature support.
     */
    public function test_extract_temperature_unsupported_model(): void {
        $this->resetAfterTest();

        $modelobj = $this->create_model('test-no-temp', null);

        $data = new stdClass();
        $data->model = $modelobj->get_id();
        $data->temperatureusecustom = 0;
        $data->temperatureprechoice = 'selection_balanced';
        $data->temperaturecustom = '';

        $result = aitool_option_temperature::extract_temperature_to_store($data);
        $this->assertNull($result);
    }

    /**
     * Test add_temperature_to_form_data correctly maps back prechoice values for default range.
     */
    public function test_add_temperature_to_form_data_default_range(): void {
        $this->resetAfterTest();

        $modelobj = $this->create_model('test-formdata-default', '0.0-1.0');

        $result = aitool_option_temperature::add_temperature_to_form_data('0.8', $modelobj);
        $this->assertSame('selection_creative', $result->temperatureprechoice);
        $this->assertEquals(0, $result->temperatureusecustom);

        $result = aitool_option_temperature::add_temperature_to_form_data('0.5', $modelobj);
        $this->assertSame('selection_balanced', $result->temperatureprechoice);
        $this->assertEquals(0, $result->temperatureusecustom);

        $result = aitool_option_temperature::add_temperature_to_form_data('0.2', $modelobj);
        $this->assertSame('selection_precise', $result->temperatureprechoice);
        $this->assertEquals(0, $result->temperatureusecustom);
    }

    /**
     * Test add_temperature_to_form_data correctly maps back prechoice values for wide range.
     */
    public function test_add_temperature_to_form_data_wide_range(): void {
        $this->resetAfterTest();

        $modelobj = $this->create_model('test-formdata-wide', '0.0-2.0');

        $result = aitool_option_temperature::add_temperature_to_form_data('1.6', $modelobj);
        $this->assertSame('selection_creative', $result->temperatureprechoice);
        $this->assertEquals(0, $result->temperatureusecustom);

        $result = aitool_option_temperature::add_temperature_to_form_data('1.0', $modelobj);
        $this->assertSame('selection_balanced', $result->temperatureprechoice);
        $this->assertEquals(0, $result->temperatureusecustom);

        $result = aitool_option_temperature::add_temperature_to_form_data('0.4', $modelobj);
        $this->assertSame('selection_precise', $result->temperatureprechoice);
        $this->assertEquals(0, $result->temperatureusecustom);
    }

    /**
     * Test add_temperature_to_form_data falls back to custom when value doesn't match any prechoice.
     */
    public function test_add_temperature_to_form_data_custom_fallback(): void {
        $this->resetAfterTest();

        $modelobj = $this->create_model('test-formdata-custom', '0.0-1.0');

        $result = aitool_option_temperature::add_temperature_to_form_data('0.73', $modelobj);
        $this->assertEquals(1, $result->temperatureusecustom);
        $this->assertEquals(0.73, $result->temperaturecustom);
    }

    /**
     * Test add_temperature_to_form_data returns defaults for unsupported model.
     */
    public function test_add_temperature_to_form_data_unsupported(): void {
        $this->resetAfterTest();

        $modelobj = $this->create_model('test-formdata-no-temp', null);

        $result = aitool_option_temperature::add_temperature_to_form_data('0.5', $modelobj);
        $this->assertObjectNotHasProperty('temperatureprechoice', $result);
        $this->assertObjectNotHasProperty('temperatureusecustom', $result);
    }

    /**
     * Test the full round-trip: extract → store → reload → form data matches original selection.
     *
     * This simulates a user filling out the form, saving, and reloading the form.
     */
    #[DataProvider('roundtrip_provider')]
    public function test_roundtrip(string $temperaturerange, string $prechoice, string $expectedstored): void {
        $this->resetAfterTest();

        $modelobj = $this->create_model('test-roundtrip-' . $prechoice . '-' . str_replace('.', '', $temperaturerange), $temperaturerange);

        // Step 1: Simulate form submission with a prechoice selection.
        $formdata = new stdClass();
        $formdata->model = $modelobj->get_id();
        $formdata->temperatureusecustom = 0;
        $formdata->temperatureprechoice = $prechoice;
        $formdata->temperaturecustom = '';

        $storedvalue = aitool_option_temperature::extract_temperature_to_store($formdata);
        $this->assertSame($expectedstored, $storedvalue);

        // Step 2: Simulate form reload with stored value.
        $reloaded = aitool_option_temperature::add_temperature_to_form_data($storedvalue, $modelobj);

        // Step 3: Validate that the original prechoice is correctly restored.
        $this->assertEquals(0, $reloaded->temperatureusecustom,
            "Prechoice '$prechoice' with range '$temperaturerange' should not fall back to custom value");
        $this->assertSame($prechoice, $reloaded->temperatureprechoice,
            "Prechoice should be '$prechoice' after round-trip with range '$temperaturerange'");
    }

    /**
     * Data provider for the round-trip test.
     *
     * @return array[]
     */
    public static function roundtrip_provider(): array {
        return [
            'default_range_creative' => ['0.0-1.0', 'selection_creative', '0.8'],
            'default_range_balanced' => ['0.0-1.0', 'selection_balanced', '0.5'],
            'default_range_precise' => ['0.0-1.0', 'selection_precise', '0.2'],
            'wide_range_creative' => ['0.0-2.0', 'selection_creative', '1.6'],
            'wide_range_balanced' => ['0.0-2.0', 'selection_balanced', '1.0'],
            'wide_range_precise' => ['0.0-2.0', 'selection_precise', '0.4'],
            'narrow_range_creative' => ['0.0-0.5', 'selection_creative', '0.4'],
            'narrow_range_balanced' => ['0.0-0.5', 'selection_balanced', '0.3'],
            'narrow_range_precise' => ['0.0-0.5', 'selection_precise', '0.1'],
            'offset_range_creative' => ['0.5-1.5', 'selection_creative', '1.3'],
            'offset_range_balanced' => ['0.5-1.5', 'selection_balanced', '1.0'],
            'offset_range_precise' => ['0.5-1.5', 'selection_precise', '0.7'],
        ];
    }

    /**
     * Test round-trip with a custom temperature value.
     */
    public function test_roundtrip_custom_value(): void {
        $this->resetAfterTest();

        $modelobj = $this->create_model('test-custom-roundtrip', '0.0-2.0');

        // Step 1: Submit with custom value.
        $formdata = new stdClass();
        $formdata->model = $modelobj->get_id();
        $formdata->temperatureusecustom = 1;
        $formdata->temperatureprechoice = 'selection_balanced';
        $formdata->temperaturecustom = '1.35';

        $storedvalue = aitool_option_temperature::extract_temperature_to_store($formdata);
        $this->assertSame('1.35', $storedvalue);

        // Step 2: Reload.
        $reloaded = aitool_option_temperature::add_temperature_to_form_data($storedvalue, $modelobj);

        // Step 3: Should be custom.
        $this->assertEquals(1, $reloaded->temperatureusecustom);
        $this->assertEquals(1.35, $reloaded->temperaturecustom);
    }

    /**
     * Test validate_temperature passes for valid custom value within range.
     */
    public function test_validate_temperature_valid(): void {
        $this->resetAfterTest();

        $modelobj = $this->create_model('test-valid-model', '0.0-2.0');

        $errors = aitool_option_temperature::validate_temperature([
            'model' => $modelobj->get_id(),
            'temperaturecustom' => '1.5',
            'temperatureusecustom' => 1,
        ]);
        $this->assertEmpty($errors);
    }

    /**
     * Test validate_temperature returns error for value outside range.
     */
    public function test_validate_temperature_out_of_range(): void {
        $this->resetAfterTest();

        $modelobj = $this->create_model('test-range-model', '0.0-1.0');

        $errors = aitool_option_temperature::validate_temperature([
            'model' => $modelobj->get_id(),
            'temperaturecustom' => '1.5',
            'temperatureusecustom' => 1,
        ]);
        $this->assertArrayHasKey('temperaturecustom', $errors);
    }

    /**
     * Test validate_temperature skips validation for unsupported model.
     */
    public function test_validate_temperature_skips_unsupported(): void {
        $this->resetAfterTest();

        $modelobj = $this->create_model('test-no-temp-validate', null);

        $errors = aitool_option_temperature::validate_temperature([
            'model' => $modelobj->get_id(),
            'temperaturecustom' => '999',
            'temperatureusecustom' => 1,
        ]);
        $this->assertEmpty($errors);
    }
}





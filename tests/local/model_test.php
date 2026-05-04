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

/**
 * Tests for the model class.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(model::class)]
final class model_test extends \advanced_testcase {
    /**
     * Helper to get the generator.
     *
     * @return \local_ai_manager_generator
     */
    private function get_generator(): \local_ai_manager_generator {
        /** @var \local_ai_manager_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_ai_manager');
        return $generator;
    }

    /**
     * Test creating and storing a new model.
     */
    public function test_create_and_store(): void {
        $this->resetAfterTest();

        $model = new model();
        $model->set_name('gpt-4o');
        $model->set_displayname('GPT-4o');
        $model->set_description('A multimodal model');
        $model->set_mimetypes('image/png,image/jpeg');
        $model->set_vision(true);
        $model->set_imggen(false);
        $model->set_tts(false);
        $model->set_stt(false);
        $model->set_temperature_range(0.0, 2.0);
        $model->set_deprecated(false);
        $model->store();

        $this->assertGreaterThan(0, $model->get_id());

        // Reload and verify.
        $loaded = new model($model->get_id());
        $this->assertEquals('gpt-4o', $loaded->get_name());
        $this->assertEquals('GPT-4o', $loaded->get_displayname());
        $this->assertEquals('A multimodal model', $loaded->get_description());
        $this->assertEquals('image/png,image/jpeg', $loaded->get_mimetypes());
        $this->assertTrue($loaded->supports_vision());
        $this->assertFalse($loaded->supports_imggen());
        $this->assertFalse($loaded->supports_tts());
        $this->assertFalse($loaded->supports_stt());
        $this->assertTrue($loaded->supports_temperature());
        $this->assertEqualsWithDelta(0.0, $loaded->get_min_temperature(), 0.001);
        $this->assertEqualsWithDelta(2.0, $loaded->get_max_temperature(), 0.001);
        $this->assertFalse($loaded->is_deprecated());
    }

    /**
     * Test updating an existing model.
     */
    public function test_update(): void {
        $this->resetAfterTest();

        $record = $this->get_generator()->create_model(['name' => 'old-model']);
        $model = new model((int) $record->id);

        $model->set_name('new-model');
        $model->set_deprecated(true);
        $model->store();

        $reloaded = new model((int) $record->id);
        $this->assertEquals('new-model', $reloaded->get_name());
        $this->assertTrue($reloaded->is_deprecated());
    }

    /**
     * Test deleting a model and its connector assignments.
     */
    public function test_delete(): void {
        global $DB;
        $this->resetAfterTest();

        $record = $this->get_generator()->create_model(['name' => 'to-delete']);
        $model = new model((int) $record->id);
        $model->add_connector('chatgpt');
        $model->add_connector('gemini');

        $model->delete();

        $this->assertFalse($DB->record_exists('local_ai_manager_model', ['id' => $record->id]));
        $this->assertFalse($DB->record_exists('local_ai_manager_model_connector', ['modelid' => $record->id]));
        $this->assertEquals(0, $model->get_id());
    }

    /**
     * Test deleting a model that does not exist throws exception.
     */
    public function test_delete_nonexistent_throws(): void {
        $this->resetAfterTest();

        $model = new model();
        $this->expectException(\moodle_exception::class);
        $model->delete();
    }

    /**
     * Test get_by_name returns model or null.
     */
    public function test_get_by_name(): void {
        $this->resetAfterTest();

        $this->get_generator()->create_model(['name' => 'findme']);

        $found = model::get_by_name('findme');
        $this->assertNotNull($found);
        $this->assertEquals('findme', $found->get_name());

        $notfound = model::get_by_name('nonexistent');
        $this->assertNull($notfound);
    }

    /**
     * Test get_all_models without filter.
     */
    public function test_get_all_models(): void {
        $this->resetAfterTest();

        $this->get_generator()->create_model(['name' => 'model-a']);
        $this->get_generator()->create_model(['name' => 'model-b', 'deprecated' => 1]);
        $this->get_generator()->create_model(['name' => 'model-c']);

        $all = model::get_all_models();
        $this->assertCount(3, $all);

        $nondeprecated = model::get_all_models(null, false);
        $this->assertCount(2, $nondeprecated);
    }

    /**
     * Test get_all_models filtered by connector.
     */
    public function test_get_all_models_by_connector(): void {
        global $DB;
        $this->resetAfterTest();

        $r1 = $this->get_generator()->create_model(['name' => 'model-x']);
        $r2 = $this->get_generator()->create_model(['name' => 'model-y']);
        $this->get_generator()->create_model(['name' => 'model-z']);

        $model1 = new model((int) $r1->id);
        $model1->add_connector('chatgpt');
        $model2 = new model((int) $r2->id);
        $model2->add_connector('chatgpt');
        $model2->add_connector('gemini');

        $chatgptmodels = model::get_all_models('chatgpt');
        $this->assertCount(2, $chatgptmodels);

        $geminimodels = model::get_all_models('gemini');
        $this->assertCount(1, $geminimodels);

        $ollamamodels = model::get_all_models('ollama');
        $this->assertCount(0, $ollamamodels);
    }

    /**
     * Test connector add/remove operations.
     */
    public function test_connector_operations(): void {
        $this->resetAfterTest();

        $record = $this->get_generator()->create_model(['name' => 'conn-test']);
        $model = new model((int) $record->id);

        $this->assertEmpty($model->get_connectors());

        $model->add_connector('chatgpt');
        $model->add_connector('gemini');
        $this->assertEqualsCanonicalizing(['chatgpt', 'gemini'], $model->get_connectors());

        // Adding same connector again is a no-op.
        $model->add_connector('chatgpt');
        $this->assertCount(2, $model->get_connectors());

        $model->remove_connector('chatgpt');
        $this->assertEquals(['gemini'], $model->get_connectors());

        // Removing non-assigned connector is a no-op.
        $model->remove_connector('ollama');
        $this->assertCount(1, $model->get_connectors());
    }

    /**
     * Test add_connector throws if model is not stored.
     */
    public function test_add_connector_to_unsaved_model_throws(): void {
        $this->resetAfterTest();

        $model = new model();
        $this->expectException(\coding_exception::class);
        $model->add_connector('chatgpt');
    }

    /**
     * Test temperature range with equal min and max (fixed temperature).
     */
    public function test_fixed_temperature(): void {
        $this->resetAfterTest();

        $model = new model();
        $model->set_name('fixed-temp');
        $model->set_temperature_range(1.0, 1.0);
        $model->store();

        $loaded = new model($model->get_id());
        $this->assertTrue($loaded->supports_temperature());
        $this->assertEqualsWithDelta(1.0, $loaded->get_min_temperature(), 0.001);
        $this->assertEqualsWithDelta(1.0, $loaded->get_max_temperature(), 0.001);
    }

    /**
     * Test set_temperature_range with min > max throws.
     */
    public function test_temperature_range_invalid_throws(): void {
        $this->resetAfterTest();

        $model = new model();
        $this->expectException(\coding_exception::class);
        $model->set_temperature_range(2.0, 1.0);
    }

    /**
     * Test disabling temperature support.
     */
    public function test_disable_temperature(): void {
        $this->resetAfterTest();

        $record = $this->get_generator()->create_model(['name' => 'temp-model', 'temperature' => '0.0-2.0']);
        $model = new model((int) $record->id);
        $this->assertTrue($model->supports_temperature());

        $model->set_temperature_range(null, null);
        $model->store();

        $reloaded = new model((int) $record->id);
        $this->assertFalse($reloaded->supports_temperature());
        $this->assertNull($reloaded->get_min_temperature());
        $this->assertNull($reloaded->get_max_temperature());
    }

    /**
     * Test record_exists for new and loaded models.
     */
    public function test_record_exists(): void {
        $this->resetAfterTest();

        $model = new model();
        $this->assertFalse($model->record_exists());

        $record = $this->get_generator()->create_model(['name' => 'exists-test']);
        $loaded = new model((int) $record->id);
        $this->assertTrue($loaded->record_exists());

        // Non-existent ID.
        $nonexistent = new model(999999);
        $this->assertFalse($nonexistent->record_exists());
    }
}


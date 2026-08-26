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

namespace local_ai_manager;

use core_plugin_manager;
use local_ai_manager\local\connector_factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Test class for the base_connector class.
 *
 * @package    local_ai_manager
 * @copyright  2025 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(base_connector::class)]
final class base_connector_test extends \advanced_testcase {
    /**
     * Test if all connector plugins implement a model definition for each existing purpose.
     */
    #[DataProvider('get_models_by_purpose_all_purposes_exist_provider')]
    public function test_get_models_by_purpose_all_purposes_exist(string $purpose): void {
        $connectorfactory = \core\di::get(connector_factory::class);
        foreach (core_plugin_manager::instance()->get_installed_plugins('aitool') as $connector => $version) {
            $connectorinstance = $connectorfactory->get_connector_by_connectorname($connector);
            $definedpurposes = array_keys($connectorinstance->get_models_by_purpose());
            if (!in_array($purpose, $definedpurposes)) {
                $this->fail('The connector "' . $connector . '" does not implement a definition for purpose "' . $purpose
                        . '" in the method \local_ai_manager\base_connector::get_models_by_purpose. Please add one.'
                        . ' If no models should be defined for a purpose, implement it with an empty array.');
            }
        }
    }

    /**
     * Test if connector plugins do not implement a definition for a non-existing purpose.
     */
    public function test_get_models_by_purpose_no_wrong_purposes(): void {
        $existingpurposes = array_keys(core_plugin_manager::instance()->get_installed_plugins('aipurpose'));
        $connectorfactory = \core\di::get(connector_factory::class);
        foreach (core_plugin_manager::instance()->get_installed_plugins('aitool') as $connector => $version) {
            $connectorinstance = $connectorfactory->get_connector_by_connectorname($connector);
            $definedpurposes = array_keys($connectorinstance->get_models_by_purpose());
            foreach ($definedpurposes as $definedpurpose) {
                if (!in_array($definedpurpose, $existingpurposes)) {
                    $this->fail('The connector "' . $connector . '" defines a purpose "' . $definedpurpose
                            . '" which is not installed. '
                            . 'Please remove it from the method \local_ai_manager\base_connector::get_models_by_purpose.');
                }
            }
        }
    }

    /**
     * Data provider for {@see \local_ai_manager\base_connector_test::test_get_models_by_purpose_all_purposes_exist}.
     *
     * It returns all installed purpose plugins in the format [ ['purpose' => 'chat'], ['purpose' => 'feedback'], ...]
     *
     * @return array a list of purposes formatted as suitable for a data provider
     */
    public static function get_models_by_purpose_all_purposes_exist_provider(): array {
        $purposeplugins = array_keys(core_plugin_manager::instance()->get_installed_plugins('aipurpose'));
        $purposes = [];
        foreach ($purposeplugins as $purposeplugin) {
            $purposes[] = ['purpose' => $purposeplugin];
        }
        return $purposes;
    }

    /**
     * Test that models are assigned to purposes based on their capability attributes.
     */
    public function test_get_models_by_purpose(): void {
        $this->resetAfterTest();

        /** @var \local_ai_manager_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_ai_manager');

        $records = [
            'text-model' => $generator->create_model([
                'name' => 'text-model',
                'textgeneration' => 1,
                'vision' => 0,
                'imggen' => 0,
                'tts' => 0,
                'stt' => 0,
                'embedding' => 0,
            ]),
            'vision-model' => $generator->create_model([
                'name' => 'vision-model',
                'textgeneration' => 1,
                'vision' => 1,
                'imggen' => 0,
                'tts' => 0,
                'stt' => 0,
                'embedding' => 0,
            ]),
            'imggen-model' => $generator->create_model([
                'name' => 'imggen-model',
                'textgeneration' => 0,
                'vision' => 0,
                'imggen' => 1,
                'tts' => 0,
                'stt' => 0,
                'embedding' => 0,
            ]),
            'tts-model' => $generator->create_model([
                'name' => 'tts-model',
                'textgeneration' => 0,
                'vision' => 0,
                'imggen' => 0,
                'tts' => 1,
                'stt' => 0,
                'embedding' => 0,
            ]),
            'stt-model' => $generator->create_model([
                'name' => 'stt-model',
                'textgeneration' => 0,
                'vision' => 0,
                'imggen' => 0,
                'tts' => 0,
                'stt' => 1,
                'embedding' => 0,
            ]),
            'embedding-model' => $generator->create_model([
                'name' => 'embedding-model',
                'textgeneration' => 0,
                'vision' => 0,
                'imggen' => 0,
                'tts' => 0,
                'stt' => 1,
                'embedding' => 1,
            ]),
        ];

        foreach ($records as $record) {
            $model = new \local_ai_manager\local\model((int) $record->id);
            $model->add_connector('chatgpt');
        }

        $connectorfactory = \core\di::get(connector_factory::class);
        $connector = $connectorfactory->get_connector_by_connectorname('chatgpt');
        $modelsbypurpose = $connector->get_models_by_purpose();

        $textpurposes = ['chat', 'agent', 'feedback', 'questiongeneration', 'singleprompt', 'translate'];
        foreach ($textpurposes as $purpose) {
            $this->assertContains(
                'text-model',
                $modelsbypurpose[$purpose],
                "text-model should be in purpose '$purpose'"
            );
            $this->assertContains(
                'vision-model',
                $modelsbypurpose[$purpose],
                "vision-model should be in purpose '$purpose'"
            );
            $this->assertNotContains(
                'imggen-model',
                $modelsbypurpose[$purpose],
                "imggen-model should NOT be in text purpose '$purpose'"
            );
            $this->assertNotContains(
                'tts-model',
                $modelsbypurpose[$purpose],
                "tts-model should NOT be in text purpose '$purpose'"
            );
            $this->assertNotContains(
                'stt-model',
                $modelsbypurpose[$purpose],
                "stt-model should NOT be in text purpose '$purpose'"
            );
            $this->assertNotContains(
                'embedding-model',
                $modelsbypurpose[$purpose],
                "embedding-model should NOT be in text purpose '$purpose'"
            );
        }

        // Verify specialized purposes.
        $this->assertContains('imggen-model', $modelsbypurpose['imggen']);
        $this->assertContains('tts-model', $modelsbypurpose['tts']);
        $this->assertContains('vision-model', $modelsbypurpose['itt']);
        // TODO Remove the if check here once we have an stt purpose.
        if (array_key_exists('stt', $modelsbypurpose)) {
            $this->assertContains('stt-model', $modelsbypurpose['stt']);
        }
        $this->assertContains('embedding-model', $modelsbypurpose['embedding']);
    }
}

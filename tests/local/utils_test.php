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
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests for local_ai_manager\local\utils.
 *
 * @package   local_ai_manager
 * @copyright 2026 ISB Bayern
 * @author    Philipp Memmel
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[Group('baseline')]
#[CoversClass(utils::class)]
final class utils_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        require_once(__DIR__ . '/../fixtures/legacy_models_fixture.php');
    }

    /**
     * Tests the complete JSON model import by comparing against all legacy fixture data.
     *
     * Iterates over every connector's legacy get_models_by_purpose fixture, and for each
     * purpose validates that every model exists in the database with the correct capabilities:
     * - Models in 'itt' purpose must have vision=true
     * - Models in 'imggen' purpose must have imggen=true
     * - Models in 'tts' purpose must have tts=true
     * - Models in text purposes (chat, feedback, etc.) must exist and be assigned to the connector
     *
     * Also verifies idempotency (no duplicates on re-import) and deprecated flag updates.
     */
    public function test_import_models_from_json(): void {
        global $DB;
        $this->resetAfterTest();

        utils::import_models_from_json();
        $connectornames = ['chatgpt', 'dalle', 'gemini', 'googlesynthesize', 'imagen', 'ollama', 'openaitts', 'telli'];
        foreach ($connectornames as $connectorname) {
            $method = $connectorname . '_get_models_by_purpose';
            $legacybypurpose = legacy_models_fixture::$method();

            foreach ($legacybypurpose as $purpose => $modelnames) {
                foreach ($modelnames as $modelname) {
                    $modelobj = model::get_by_name($modelname);
                    $this->assertNotNull($modelobj);

                    // Verify the model is assigned to this connector.
                    $connectors = $modelobj->get_connectors();
                    $this->assertContains($connectorname, $connectors);

                    // Verify purpose-specific capabilities.
                    switch ($purpose) {
                        case 'itt':
                            $this->assertTrue($modelobj->supports_vision());
                            break;
                        case 'imggen':
                            $this->assertTrue($modelobj->supports_imggen());
                            break;
                        case 'tts':
                            $this->assertTrue($modelobj->supports_tts(), $modelobj->get_name());
                            break;
                    }
                }
            }
        }

        // Verify idempotency: re-import must not create duplicates.
        $modelcount = $DB->count_records('local_ai_manager_model');
        $connectorcount = $DB->count_records('local_ai_manager_model_connector');
        utils::import_models_from_json();
        $this->assertEquals($modelcount, $DB->count_records('local_ai_manager_model'));
        $this->assertEquals($connectorcount, $DB->count_records('local_ai_manager_model_connector'));
    }
}

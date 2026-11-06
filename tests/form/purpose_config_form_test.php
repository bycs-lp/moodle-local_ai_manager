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

namespace local_ai_manager\form;

use local_ai_manager\base_purpose;
use local_ai_manager\local\connector_factory;
use local_ai_manager\local\userinfo;

/**
 * Tests for the purpose config form.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(purpose_config_form::class)]
#[\PHPUnit\Framework\Attributes\CoversMethod(\aipurpose_embedding\purpose::class, 'requires_same_model_for_all_roles')]
final class purpose_config_form_test extends \advanced_testcase {
    /**
     * Create an AI tool instance using a model.
     *
     * @param string $model The model name.
     * @return int The id of the instance.
     */
    private function create_tool_instance(string $model): int {
        $instance = \core\di::get(connector_factory::class)->get_new_instance('chatgpt');
        $instance->set_name('Tool ' . $model);
        $instance->set_model_id_from_name($model);
        $instance->store();
        return $instance->get_id();
    }

    /**
     * Data provider for test_validation_requires_same_embedding_model.
     *
     * @return array Datasets with purpose, the models assigned to the basic and extended role and whether an error is expected.
     */
    public static function validation_requires_same_embedding_model_provider(): array {
        return [
            'embedding_different_models' => [
                'purpose' => 'embedding', 'basicmodel' => 'embed-a', 'extendedmodel' => 'embed-b', 'expectederror' => true,
            ],
            'embedding_same_model_other_tool' => [
                'purpose' => 'embedding', 'basicmodel' => 'embed-a', 'extendedmodel' => 'embed-a2', 'expectederror' => false,
            ],
            'embedding_only_one_role' => [
                'purpose' => 'embedding', 'basicmodel' => null, 'extendedmodel' => 'embed-b', 'expectederror' => false,
            ],
            'chat_different_models' => [
                'purpose' => 'chat', 'basicmodel' => 'embed-a', 'extendedmodel' => 'embed-b', 'expectederror' => false,
            ],
        ];
    }

    /**
     * Purposes requiring the same model for all roles reject AI tools with different models.
     *
     * @param string $purpose The purpose to configure.
     * @param ?string $basicmodel Key of the tool assigned to the basic role, null for none.
     * @param string $extendedmodel Key of the tool assigned to the extended role.
     * @param bool $expectederror Whether a validation error is expected.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('validation_requires_same_embedding_model_provider')]
    public function test_validation_requires_same_embedding_model(
        string $purpose,
        ?string $basicmodel,
        string $extendedmodel,
        bool $expectederror,
    ): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \local_ai_manager_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_ai_manager');
        $generator->create_model(['name' => 'embedding-model-a']);
        $generator->create_model(['name' => 'embedding-model-b']);
        // Two tools using the same model.
        $tools = [
            'embed-a' => $this->create_tool_instance('embedding-model-a'),
            'embed-a2' => $this->create_tool_instance('embedding-model-a'),
            'embed-b' => $this->create_tool_instance('embedding-model-b'),
        ];

        $basickey = base_purpose::get_purpose_tool_config_key($purpose, userinfo::ROLE_BASIC);
        $extendedkey = base_purpose::get_purpose_tool_config_key($purpose, userinfo::ROLE_EXTENDED);
        $data = [
            $basickey => $basicmodel === null ? 0 : $tools[$basicmodel],
            $extendedkey => $tools[$extendedmodel],
        ];

        $errors = (new purpose_config_form())->validation($data, []);

        if ($expectederror) {
            $this->assertSame([$extendedkey => get_string('formvalidation_purpose_samemodel', 'local_ai_manager')], $errors);
        } else {
            $this->assertSame([], $errors);
        }
    }
}

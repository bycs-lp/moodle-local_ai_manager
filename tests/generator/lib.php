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

/**
 * Data generator for local_ai_manager.
 *
 * @package   local_ai_manager
 * @copyright 2025 ISB Bayern
 * @author    Philipp Memmel
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_ai_manager_generator extends component_generator_base {
    /**
     * Create a (fake) request log entry.
     *
     * @param array $record the record data if you want to override any defaults.
     * @return stdClass the created log entry.
     */
    public function create_request_log_entry(array $record = []): stdClass {
        global $DB, $USER;
        $block = $this->datagenerator->create_block(
        // We, of course, usually are using block_ai_chat, not block_html, but we do not
        // want to introduce an unnecessary dependency here, and it does not matter for the log entry.
            'html',
            ['parentcontextid' => SYSCONTEXTID]
        );
        $itemid = isset($record['itemid']) ? $record['itemid'] : 10;
        $contextid = \context_block::instance($block->id)->id;
        $default = [
            'userid' => $USER->id,
            'tenant' => '',
            'value' => 132.000,
            'customvalue1' => '80.000',
            'customvalue2' => '52.000',
            'purpose' => 'chat',
            'connector' => 'chatgpt',
            'model' => 'gpt-4o',
            'modelinfo' => 'gpt-4o-2024-08-06',
            'duration' => '3.243',
            'prompttext' => 'Some example prompt',
            'promptcompletion' => 'Some example completion',
            'requestoptions' => '{"itemid":' . $itemid .
                ',"conversationcontext":[{"message":"Hi, I\'m Fred.","sender":"user"},'
                . '{"message":"<p>Hi Fred, what can I do for you?</p>","sender":"ai"}]}',
            'component' => 'block_ai_chat',
            'contextid' => $contextid,
            'coursecontextid' => 1,
            'itemid' => 10,
            'deleted' => 0,
            'timecreated' => time(),
        ];

        $data = (object) array_merge($default, $record);
        $data->id = $DB->insert_record('local_ai_manager_request_log', $data);

        return $data;
    }

    /**
     * Create a model record.
     *
     * @param array $record the record data if you want to override any defaults. Additionally, the key 'connectors'
     *  can contain a list of connector names the model should be assigned to.
     * @return stdClass the created model record (with id set).
     */
    public function create_model(array $record = []): stdClass {
        global $DB;
        $connectors = $record['connectors'] ?? [];
        unset($record['connectors']);
        $default = [
            'name' => 'test-model',
            'displayname' => 'Test Model',
            'description' => '',
            'mimetypes' => '',
            'textgeneration' => 1,
            'vision' => 0,
            'imggen' => 0,
            'tts' => 0,
            'stt' => 0,
            'temperature' => null,
            'deprecated' => 0,
            'timecreated' => 0,
            'timemodified' => 0,
        ];
        $data = (object) array_merge($default, $record);
        $data->id = $DB->insert_record('local_ai_manager_model', $data);
        if (!empty($connectors)) {
            $model = new \local_ai_manager\local\model((int) $data->id);
            foreach ($connectors as $connector) {
                $model->add_connector($connector);
            }
        }
        return $data;
    }

    /**
     * Create and store a connector instance.
     *
     * @param array $record the instance data if you want to override any defaults. Supported keys: 'connector', 'name',
     *  'tenant' and 'modelid'.
     * @return \local_ai_manager\base_instance the stored instance object
     */
    public function create_instance(array $record = []): \local_ai_manager\base_instance {
        $default = [
            'connector' => 'chatgpt',
            'name' => 'Test instance',
            'tenant' => \core\di::get(\local_ai_manager\local\tenant_factory::class)->get()->get_identifier(),
            'modelid' => null,
        ];
        $data = array_merge($default, $record);
        $instance = \core\di::get(\local_ai_manager\local\connector_factory::class)->get_new_instance($data['connector']);
        $instance->set_name($data['name']);
        $instance->set_tenant($data['tenant']);
        if (!is_null($data['modelid'])) {
            $instance->set_model_id((int) $data['modelid']);
        }
        $instance->store();
        return $instance;
    }

    /**
     * Allows a user to use the AI manager in the current tenant.
     *
     * Assigns a role with the capability 'local/ai_manager:use' in the system context, enables the current tenant and stores
     * a confirmed, unlocked user info with scope "everywhere". The current tenant is the tenant of the current user, unless it
     * has been set explicitly (see {@see \local_ai_manager\local\tenant_factory}), so set the user before.
     *
     * @param stdClass $user the user
     * @param int $role the AI manager role of the user, one of the userinfo::ROLE_* constants
     */
    public function enable_ai_usage(stdClass $user, int $role = \local_ai_manager\local\userinfo::ROLE_BASIC): void {
        global $DB;
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'aiuser'])
            ?: $this->datagenerator->create_role(['shortname' => 'aiuser']);
        role_assign($roleid, $user->id, SYSCONTEXTID);
        assign_capability('local/ai_manager:use', CAP_ALLOW, $roleid, SYSCONTEXTID);

        \core\di::get(\local_ai_manager\local\config_manager::class)->set_config('tenantenabled', true);

        $userinfo = new \local_ai_manager\local\userinfo($user->id);
        $userinfo->set_confirmed(true);
        $userinfo->set_locked(false);
        $userinfo->set_role($role);
        $userinfo->set_scope(\local_ai_manager\local\userinfo::SCOPE_EVERYWHERE);
        $userinfo->store();
    }

    /**
     * Creates an AI tool instance in the current tenant and assigns it to a purpose for a role.
     *
     * @param string $purpose the purpose name
     * @param int $role the role the tool is assigned for, one of the userinfo::ROLE_* constants
     * @param string $connector the name of the aitool subplugin
     * @param string $model the name of the model of the instance
     * @return \local_ai_manager\base_instance the stored instance
     */
    public function create_purpose_tool(
        string $purpose,
        int $role = \local_ai_manager\local\userinfo::ROLE_BASIC,
        string $connector = 'chatgpt',
        string $model = 'gpt-4o'
    ): \local_ai_manager\base_instance {
        $instance = \core\di::get(\local_ai_manager\local\connector_factory::class)->get_new_instance($connector);
        $instance->set_model_id_from_name($model);
        $instance->store();
        \core\di::get(\local_ai_manager\local\config_manager::class)->set_config(
            \local_ai_manager\base_purpose::get_purpose_tool_config_key($purpose, $role),
            $instance->get_id()
        );
        return $instance;
    }
}

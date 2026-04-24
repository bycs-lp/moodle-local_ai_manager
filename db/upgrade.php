<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Upgrade functions for local_ai_manager.
 *
 * @package   local_ai_manager
 * @copyright 2024 ISB Bayern
 * @author    Philipp Memmel
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_ai_manager\local\userinfo;

/**
 * Define upgrade steps to be performed to upgrade the plugin from the old version to the current one.
 *
 * @param int $oldversion Version number the plugin is being upgraded from.
 */
function xmldb_local_ai_manager_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2024080101) {
        $table = new xmldb_table('local_ai_manager_instance');
        $field = new xmldb_field('customfield5', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'customfield4');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2024080101, 'local', 'ai_manager');
    }

    if ($oldversion < 2024080900) {
        // Changing precision of field duration on table local_ai_manager_request_log to (20, 3).
        $table = new xmldb_table('local_ai_manager_request_log');
        $field = new xmldb_field('duration', XMLDB_TYPE_NUMBER, '20, 3', null, null, null, null, 'modelinfo');

        // Launch change of precision for field duration.
        $dbman->change_field_precision($table, $field);

        // Ai_manager savepoint reached.
        upgrade_plugin_savepoint(true, 2024080900, 'local', 'ai_manager');
    }

    if ($oldversion < 2024091800) {
        $table = new xmldb_table('local_ai_manager_request_log');
        $field = new xmldb_field('connector', XMLDB_TYPE_CHAR, '50', null, null, null, null, 'purpose');

        // Conditionally launch add field connector.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Migrate existing records.
        $rs = $DB->get_recordset('local_ai_manager_request_log');
        foreach ($rs as $record) {
            if ($record->model === 'preconfigured') {
                if ($record->purpose === 'tts') {
                    $record->model = 'openaitts_preconfigured_azure';
                    $record->modelinfo = 'openaitts_preconfigured_azure';
                } else if ($record->purpose === 'imggen') {
                    $record->model = 'dalle_preconfigured_azure';
                    $record->modelinfo = 'dalle_preconfigured_azure';
                } else {
                    $record->model = 'chatgpt_preconfigured_azure';
                }
            }
            if ($record->purpose === 'tts') {
                if ($record->model === 'openaitts_preconfigured_azure' || $record->model === 'tts-1') {
                    $record->connector = 'openaitts';
                } else {
                    $record->connector = 'googlesynthesize';
                }
            } else if ($record->purpose === 'imggen') {
                $record->connector = 'dalle';
            } else {
                // We have a text based language model.
                if (str_starts_with($record->model, 'gemini-')) {
                    $record->connector = 'gemini';
                } else if (str_starts_with($record->model, 'gpt-') || $record->model === 'chatgpt_preconfigured_azure') {
                    $record->connector = 'chatgpt';
                } else {
                    $record->connector = 'ollama';
                }
            }
            $DB->update_record('local_ai_manager_request_log', $record);
        }
        $rs->close();

        $rs = $DB->get_recordset('local_ai_manager_instance');
        foreach ($rs as $record) {
            if ($record->model === 'preconfigured') {
                if ($record->connector === 'chatgpt') {
                    $record->model = 'chatgpt_preconfigured_azure';
                } else if ($record->connector === 'openaitts') {
                    $record->model = 'openaitts_preconfigured_azure';
                } else if ($record->connector === 'dalle') {
                    $record->model = 'dalle_preconfigured_azure';
                }
            }
            $DB->update_record('local_ai_manager_instance', $record);
        }

        $rs->close();

        upgrade_plugin_savepoint(true, 2024091800, 'local', 'ai_manager');
    }

    if ($oldversion < 2024092600) {
        $sqllike = $DB->sql_like('configkey', ':configkeypattern');
        $sql = "SELECT * FROM {local_ai_manager_config} WHERE $sqllike";
        $rs = $DB->get_recordset_sql($sql, ['configkeypattern' => 'purpose_%_tool']);
        foreach ($rs as $record) {
            $oldconfigkey = $record->configkey;
            $record->configkey = $oldconfigkey . '_role_basic';
            $DB->update_record('local_ai_manager_config', $record);
            $roleextendedrecord = clone($record);
            unset($roleextendedrecord->id);
            $roleextendedrecord->configkey = $oldconfigkey . '_role_extended';
            if (
                !$DB->record_exists(
                    'local_ai_manager_config',
                    [
                        'configkey' => $roleextendedrecord->configkey,
                        'tenant' => $roleextendedrecord->tenant,
                    ]
                )
            ) {
                $DB->insert_record('local_ai_manager_config', $roleextendedrecord);
            }
        }
        $rs->close();

        upgrade_plugin_savepoint(true, 2024092600, 'local', 'ai_manager');
    }

    if ($oldversion < 2024110501) {
        // Changing type of field customfield1 on table local_ai_manager_instance to text.
        $table = new xmldb_table('local_ai_manager_instance');
        $field = new xmldb_field('customfield1', XMLDB_TYPE_TEXT, null, null, null, null, null, 'infolink');
        $dbman->change_field_type($table, $field);
        $field = new xmldb_field('customfield2', XMLDB_TYPE_TEXT, null, null, null, null, null, 'customfield1');
        $dbman->change_field_type($table, $field);
        $field = new xmldb_field('customfield3', XMLDB_TYPE_TEXT, null, null, null, null, null, 'customfield2');
        $dbman->change_field_type($table, $field);
        $field = new xmldb_field('customfield4', XMLDB_TYPE_TEXT, null, null, null, null, null, 'customfield3');
        $dbman->change_field_type($table, $field);
        $field = new xmldb_field('customfield5', XMLDB_TYPE_TEXT, null, null, null, null, null, 'customfield4');
        $dbman->change_field_type($table, $field);

        // Ai_manager savepoint reached.
        upgrade_plugin_savepoint(true, 2024110501, 'local', 'ai_manager');
    }

    if ($oldversion < 2024120200) {
        $rs = $DB->get_recordset('local_ai_manager_instance', ['connector' => 'gemini']);
        foreach ($rs as $record) {
            $record->customfield2 = 'googleai';
            $record->model = str_replace('-latest', '', $record->model);
            $record->endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . $record->model . ':generateContent';
            $DB->update_record('local_ai_manager_instance', $record);
        }
        $rs->close();

        // AI manager savepoint reached.
        upgrade_plugin_savepoint(true, 2024120200, 'local', 'ai_manager');
    }

    if ($oldversion < 2025010701) {
        // Define field scope to be added to local_ai_manager_userinfo.
        $table = new xmldb_table('local_ai_manager_userinfo');
        $field = new xmldb_field('scope', XMLDB_TYPE_INTEGER, '1', null, null, null, null, 'confirmed');

        // Conditionally launch add field scope.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $userids = $DB->get_fieldset('local_ai_manager_userinfo', 'userid');
        foreach ($userids as $userid) {
            // This will set the correct default value for the "scope" and update the record afterwards.
            $userinfo = new userinfo($userid);
            $userinfo->store();
        }

        // AI manager savepoint reached.
        upgrade_plugin_savepoint(true, 2025010701, 'local', 'ai_manager');
    }

    if ($oldversion < 2025012200) {
        // Instance table.
        $table = new xmldb_table('local_ai_manager_instance');
        $field = new xmldb_field('tenant', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'name');
        $dbman->change_field_precision($table, $field);

        // Config table.
        $table = new xmldb_table('local_ai_manager_config');
        // Remove indexes first to be sure.
        $index = new xmldb_index('tenant', XMLDB_INDEX_NOTUNIQUE, ['tenant']);
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }
        $index = new xmldb_index('configkey_tenant', XMLDB_INDEX_UNIQUE, ['configkey', 'tenant']);
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }
        // Change precision of tenant field.
        $field = new xmldb_field('tenant', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'configvalue');
        $dbman->change_field_precision($table, $field);
        // Reapply indexes.
        $index = new xmldb_index('tenant', XMLDB_INDEX_NOTUNIQUE, ['tenant']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        // We also change this index to unique.
        $index = new xmldb_index('configkey_tenant', XMLDB_INDEX_UNIQUE, ['configkey', 'tenant']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Add tenant field to request log table and do update step.
        // Define field tenant to be added to local_ai_manager_request_log.
        $table = new xmldb_table('local_ai_manager_request_log');
        $field = new xmldb_field('tenant', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'userid');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $rs = $DB->get_recordset('local_ai_manager_request_log');
        foreach ($rs as $record) {
            // We intentionally access the DB directly, because we want the record, even if it is suspended, deleted etc.
            $user = $DB->get_record('user', ['id' => $record->userid]);
            $tenantfield = get_config('local_ai_manager', 'tenantcolumn');
            $record->tenant = trim($user->{$tenantfield});
            $DB->update_record('local_ai_manager_request_log', $record);
        }
        $rs->close();

        upgrade_plugin_savepoint(true, 2025012200, 'local', 'ai_manager');
    }

    if ($oldversion < 2025021700) {
        $table = new xmldb_table('local_ai_manager_request_log');
        $field = new xmldb_field('coursecontextid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'contextid');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $rs = $DB->get_recordset('local_ai_manager_request_log');
        foreach ($rs as $record) {
            if (empty($record->contextid)) {
                // This should not really happen. But there might be plugins that did not properly send a context id before it was
                // required.
                $record->contextid = SYSCONTEXTID;
                $record->coursecontextid = SYSCONTEXTID;
            }
            $context = context::instance_by_id($record->contextid, IGNORE_MISSING);
            if (!$context) {
                $record->coursecontextid = SYSCONTEXTID;
            } else {
                $closestparentcontext = \local_ai_manager\ai_manager_utils::find_closest_parent_course_context($context);
                if (is_null($closestparentcontext)) {
                    $record->coursecontextid = SYSCONTEXTID;
                } else {
                    $record->coursecontextid = $closestparentcontext->id;
                }
            }
            $DB->update_record('local_ai_manager_request_log', $record);
        }
        $rs->close();

        // Ai_manager savepoint reached.
        upgrade_plugin_savepoint(true, 2025021700, 'local', 'ai_manager');
    }

    if ($oldversion < 2025022102) {
        $table = new xmldb_table('local_ai_manager_request_log');
        $key = new xmldb_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $dbman->add_key($table, $key);
        $key = new xmldb_key('contextid', XMLDB_KEY_FOREIGN, ['contextid'], 'context', ['id']);
        $dbman->add_key($table, $key);

        upgrade_plugin_savepoint(true, 2025022102, 'local', 'ai_manager');
    }

    if ($oldversion < 2025073000) {
        // Changing type of field apikey on table local_ai_manager_instance to text.
        $table = new xmldb_table('local_ai_manager_instance');
        $field = new xmldb_field('apikey', XMLDB_TYPE_TEXT, null, null, null, null, null, 'endpoint');
        $dbman->change_field_type($table, $field);

        // Ai_manager savepoint reached.
        upgrade_plugin_savepoint(true, 2025073000, 'local', 'ai_manager');
    }

    if ($oldversion < 2025073100) {
        $rs = $DB->get_recordset('local_ai_manager_instance', ['model' => 'openaitts_preconfigured_azure']);
        foreach ($rs as $record) {
            $record->model = 'openaitts_tts-1_preconfigured_azure';
            $DB->update_record('local_ai_manager_instance', $record);
        }
        $rs->close();

        $rs = $DB->get_recordset('local_ai_manager_request_log', ['model' => 'openaitts_preconfigured_azure']);
        foreach ($rs as $record) {
            $record->model = 'openaitts_tts-1_preconfigured_azure';
            $record->modelinfo = 'openaitts_tts-1_preconfigured_azure';
            $DB->update_record('local_ai_manager_request_log', $record);
        }
        $rs->close();

        upgrade_plugin_savepoint(true, 2025073100, 'local', 'ai_manager');
    }

    if ($oldversion < 2025082900) {
        unset_config('dataprocessing', 'local_ai_manager');
        unset_config('legalroles', 'local_ai_manager');
        unset_config('termsofuselegal', 'local_ai_manager');

        upgrade_plugin_savepoint(true, 2025082900, 'local', 'ai_manager');
    }

    if ($oldversion < 2026020600) {
        $table = new xmldb_table('local_ai_manager_instance');
        $field = new xmldb_field('useglobalapikey', XMLDB_TYPE_INTEGER, '1', null, null, null, null, 'apikey');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026020600, 'local', 'ai_manager');
    }

    if ($oldversion < 2026051600) {
        // MBS-10761: Tool-agent schema — four new tables.
        // Create local_ai_manager_agent_runs.
        $table = new xmldb_table('local_ai_manager_agent_runs');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('conversationid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('contextid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('tenantid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('component', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, 'block_ai_chat');
        $table->add_field('mode', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, null);
        $table->add_field('connector', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('model', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '24', null, XMLDB_NOTNULL, null, 'running');
        $table->add_field('iterations', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('prompt_tokens', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('completion_tokens', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('overhead_tokens', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('entity_context', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('user_prompt', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('error_code', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        $table->add_field('error_message', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('started', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('finished', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_key('contextid', XMLDB_KEY_FOREIGN, ['contextid'], 'context', ['id']);
        $table->add_index('userid-started', XMLDB_INDEX_NOTUNIQUE, ['userid', 'started']);
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('conversationid', XMLDB_INDEX_NOTUNIQUE, ['conversationid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Create local_ai_manager_tool_calls.
        $table = new xmldb_table('local_ai_manager_tool_calls');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('runid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('callindex', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('llm_call_id', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        $table->add_field('toolname', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('args_json', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('args_hash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('result_json', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('approval_state', XMLDB_TYPE_CHAR, '24', null, XMLDB_NOTNULL, null, 'auto');
        $table->add_field('approved_by', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('approved_at', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('duration_ms', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('error_code', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        $table->add_field('error_message', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('retry_count', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('undo_payload', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('undone_at', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('affected_objects', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('runid', XMLDB_KEY_FOREIGN, ['runid'], 'local_ai_manager_agent_runs', ['id']);
        $table->add_index('runid-callindex', XMLDB_INDEX_UNIQUE, ['runid', 'callindex']);
        $table->add_index('toolname', XMLDB_INDEX_NOTUNIQUE, ['toolname']);
        $table->add_index('approval_state', XMLDB_INDEX_NOTUNIQUE, ['approval_state']);
        $table->add_index('args_hash', XMLDB_INDEX_NOTUNIQUE, ['args_hash']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Create local_ai_manager_trust_prefs.
        $table = new xmldb_table('local_ai_manager_trust_prefs');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('tenantid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('toolname', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('scope', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, null);
        $table->add_field('session_id', XMLDB_TYPE_CHAR, '128', null, null, null, null);
        $table->add_field('expires', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('userid-toolname-scope-session', XMLDB_INDEX_UNIQUE,
            ['userid', 'toolname', 'scope', 'session_id']);
        $table->add_index('tenantid-scope', XMLDB_INDEX_NOTUNIQUE, ['tenantid', 'scope']);
        $table->add_index('expires', XMLDB_INDEX_NOTUNIQUE, ['expires']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Create local_ai_manager_file_extract_cache.
        $table = new xmldb_table('local_ai_manager_file_extract_cache');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('contenthash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('mechanism', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, null);
        $table->add_field('extracted_text', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('pages', XMLDB_TYPE_INTEGER, '6', null, null, null, null);
        $table->add_field('truncated', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('expires', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('contenthash-mechanism', XMLDB_INDEX_UNIQUE, ['contenthash', 'mechanism']);
        $table->add_index('expires', XMLDB_INDEX_NOTUNIQUE, ['expires']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Create local_ai_manager_tool_overrides (SPEZ §19).
        $table = new xmldb_table('local_ai_manager_tool_overrides');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('toolname', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('tenantid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('llm_description_override', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('describe_for_user_template', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('example_appendix', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('glossary_json', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('toolname-tenantid', XMLDB_INDEX_UNIQUE, ['toolname', 'tenantid']);
        $table->add_index('enabled', XMLDB_INDEX_NOTUNIQUE, ['enabled']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Bootstrap HMAC secret for approval tokens (SPEZ §9.2).
        if (!get_config('local_ai_manager', 'agent_hmac_secret')) {
            set_config('agent_hmac_secret', random_string(64), 'local_ai_manager');
        }
        // Default runtime knobs (SPEZ §17).
        if (get_config('local_ai_manager', 'agent_approval_ttl') === false) {
            set_config('agent_approval_ttl', 900, 'local_ai_manager');
        }
        if (get_config('local_ai_manager', 'agent_max_iterations') === false) {
            set_config('agent_max_iterations', 10, 'local_ai_manager');
        }
        if (get_config('local_ai_manager', 'agent_undo_window_seconds') === false) {
            set_config('agent_undo_window_seconds', 900, 'local_ai_manager');
        }
        if (get_config('local_ai_manager', 'agent_rejection_retry_limit') === false) {
            set_config('agent_rejection_retry_limit', 3, 'local_ai_manager');
        }

        upgrade_plugin_savepoint(true, 2026051600, 'local', 'ai_manager');
    }

    if ($oldversion < 2026051801) {
        // Add final_text column to agent_runs so multi-turn conversations can replay prior assistant messages.
        $table = new xmldb_table('local_ai_manager_agent_runs');
        $field = new xmldb_field('final_text', XMLDB_TYPE_TEXT, null, null, null, null, null, 'user_prompt');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026051801, 'local', 'ai_manager');
    }

    return true;
}

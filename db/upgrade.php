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

    if ($oldversion < 2025120401) {
        $table = new xmldb_table('local_ai_manager_instance');
        $field = new xmldb_field('useglobalapikey', XMLDB_TYPE_INTEGER, '1', null, null, null, null, 'apikey');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2025120401, 'local', 'ai_manager');
    }

    return true;
}

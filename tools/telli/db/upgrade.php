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
 * Upgrade steps for Telli
 *
 * Documentation: {@link https://moodledev.io/docs/guides/upgrade}
 *
 * @package    aitool_telli
 * @category   upgrade
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Execute the plugin upgrade steps from the given old version.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_aitool_telli_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2025101600) {
        // Define table aitool_telli_consumption to be created.
        $table = new xmldb_table('aitool_telli_consumption');

        // Adding fields to table aitool_telli_consumption.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('type', XMLDB_TYPE_CHAR, '10', null, null, null, null);
        $table->add_field('value', XMLDB_TYPE_NUMBER, '38, 18', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Adding keys to table aitool_telli_consumption.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Adding indexes to table aitool_telli_consumption.
        $table->add_index('type', XMLDB_INDEX_NOTUNIQUE, ['type']);

        // Conditionally launch create table for aitool_telli_consumption.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Telli savepoint reached.
        upgrade_plugin_savepoint(true, 2025101600, 'aitool', 'telli');
    }

    if ($oldversion < 2025103100) {
        // Clean up existing consumption data with epsilon-based reset detection.
        // This fixes false positive aggregate records caused by floating-point precision issues.
        $cleanup = new \aitool_telli\local\cleanup_consumption_data(false, false);
        $cleanup->execute();

        // Telli savepoint reached.
        upgrade_plugin_savepoint(true, 2025103100, 'aitool', 'telli');
    }

    if ($oldversion < 2026050400) {
        // Migrate the availablemodels setting to the model management system.
        $availablemodels = get_config('aitool_telli', 'availablemodels');
        if (!empty($availablemodels)) {
            $clock = \core\di::get(\core\clock::class);
            $now = $clock->time();

            foreach (explode("\n", $availablemodels) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $imggen = 0;
                $vision = 0;

                if (str_ends_with($line, '#IMGGEN')) {
                    $line = trim(preg_replace('/#IMGGEN$/', '', $line));
                    $imggen = 1;
                } else if (str_ends_with($line, '#VISION')) {
                    $line = trim(preg_replace('/#VISION$/', '', $line));
                    $vision = 1;
                }

                // Check if model already exists.
                $existing = $DB->get_record('local_ai_manager_model', ['name' => $line]);
                if ($existing) {
                    $modelid = (int) $existing->id;
                    // Update capabilities if the existing model does not have them yet.
                    $updated = false;
                    if ($imggen && !(int) $existing->imggen) {
                        $existing->imggen = 1;
                        $updated = true;
                    }
                    if ($vision && !(int) $existing->vision) {
                        $existing->vision = 1;
                        $updated = true;
                    }
                    if ($updated) {
                        $existing->timemodified = $now;
                        $DB->update_record('local_ai_manager_model', $existing);
                    }
                } else {
                    // Create a new model record.
                    $record = new stdClass();
                    $record->name = $line;
                    $record->displayname = $line;
                    $record->description = '';
                    $record->mimetypes = '';
                    $record->vision = $vision;
                    $record->imggen = $imggen;
                    $record->tts = 0;
                    $record->stt = 0;
                    $record->deprecated = 0;
                    $record->timecreated = $now;
                    $record->timemodified = $now;
                    $modelid = $DB->insert_record('local_ai_manager_model', $record);
                }

                // Assign the telli connector to this model.
                if (
                    !$DB->record_exists('local_ai_manager_model_purpose', [
                    'modelid' => $modelid,
                    'connector' => 'telli',
                    ])
                ) {
                    $purposerecord = new stdClass();
                    $purposerecord->modelid = $modelid;
                    $purposerecord->connector = 'telli';
                    $purposerecord->timecreated = $now;
                    $purposerecord->timemodified = $now;
                    $DB->insert_record('local_ai_manager_model_purpose', $purposerecord);
                }
            }

            // Remove the old setting.
            unset_config('availablemodels', 'aitool_telli');
        }

        // Telli savepoint reached.
        upgrade_plugin_savepoint(true, 2026050400, 'aitool', 'telli');
    }

    return true;
}

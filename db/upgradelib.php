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
 * Upgrade helper functions for local_ai_manager.
 *
 * @package   local_ai_manager
 * @copyright 2026 ISB Bayern
 * @author    Thomas Schönlein
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Cleans up legacy Azure data stored in connector instance custom fields.
 *
 * The former Azure implementation stored resource metadata in customfield3-5.
 * Only customfield3 of Gemini instances remains functionally relevant.
 *
 * @return void
 */
function local_ai_manager_cleanup_legacy_azure_instance_data(): void {
    global $DB;

    [$insql, $params] = $DB->get_in_or_equal(['chatgpt', 'dalle', 'openaitts'], SQL_PARAMS_NAMED);
    $DB->set_field_select(
        'local_ai_manager_instance',
        'customfield3',
        null,
        "connector $insql AND customfield3 IS NOT NULL",
        $params
    );
    $DB->set_field_select(
        'local_ai_manager_instance',
        'customfield4',
        null,
        "connector $insql AND customfield4 IS NOT NULL",
        $params
    );

    $DB->set_field_select(
        'local_ai_manager_instance',
        'customfield5',
        null,
        "connector $insql AND customfield5 IS NOT NULL",
        $params
    );
}

/**
 * Migrates the model field in local_ai_manager_instance from model name strings to model IDs.
 */
function local_ai_manager_migrate_instance_model_to_id(): void {
    global $DB;

    $clock = \core\di::get(\core\clock::class);
    $now = $clock->time();

    // Build a lookup map of model name => id.
    $modellookup = $DB->get_records_menu('local_ai_manager_model', null, '', 'name, id');

    $rs = $DB->get_recordset('local_ai_manager_instance');
    foreach ($rs as $record) {
        if (empty($record->model)) {
            // Should not be possible.
            continue;
        }

        // If the model field is already numeric (already migrated), skip.
        if (is_numeric($record->model)) {
            continue;
        }

        $modelname = $record->model;

        if (isset($modellookup[$modelname])) {
            $modelid = $modellookup[$modelname];
        } else {
            // Model not found in our table yet - create it as an unknown model.
            $newmodel = new \stdClass();
            $newmodel->name = $modelname;
            $newmodel->displayname = $modelname;
            $newmodel->description = 'Auto-created during migration';
            $newmodel->mimetypes = '';
            $newmodel->vision = 0;
            $newmodel->imggen = 0;
            $newmodel->tts = 0;
            $newmodel->stt = 0;
            $newmodel->timecreated = $now;
            $newmodel->timemodified = $now;
            $modelid = $DB->insert_record('local_ai_manager_model', $newmodel);
            $modellookup[$modelname] = $modelid;

            // Also create the connector assignment if we have a connector.
            if (!empty($record->connector)) {
                $purposerecord = new \stdClass();
                $purposerecord->modelid = $modelid;
                $purposerecord->connector = $record->connector;
                $purposerecord->timecreated = $now;
                $purposerecord->timemodified = $now;
                $DB->insert_record('local_ai_manager_model_purpose', $purposerecord);
            }
        }

        $record->model = (string) $modelid;
        $DB->update_record('local_ai_manager_instance', $record);
    }
    $rs->close();
}

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

/**
 * Utility class for the local_ai_manager plugin.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class utils {
    /**
     * Imports model definitions from the db/models.json file into the database.
     *
     * Populates the local_ai_manager_model and local_ai_manager_model_purpose tables.
     * Existing models are updated (deprecated flag), new models are inserted.
     */
    public static function import_models_from_json(): void {
        global $CFG, $DB;

        $jsonpath = $CFG->dirroot . '/local/ai_manager/db/models.json';
        if (!file_exists($jsonpath)) {
            throw new \moodle_exception('Models JSON file not found: ' . $jsonpath);
        }

        $models = json_decode(file_get_contents($jsonpath), true);
        if (empty($models)) {
            return;
        }

        $clock = \core\di::get(\core\clock::class);
        $now = $clock->time();

        foreach ($models as $modeldata) {
            // Check if model already exists (by name).
            $existing = $DB->get_record('local_ai_manager_model', ['name' => $modeldata['name']]);
            if ($existing) {
                $modelid = $existing->id;
                // Update deprecated flag if it has changed.
                $deprecated = (int) ($modeldata['deprecated'] ?? 0);
                if ((int) $existing->deprecated !== $deprecated) {
                    $existing->deprecated = $deprecated;
                    $existing->timemodified = $now;
                    $DB->update_record('local_ai_manager_model', $existing);
                }
            } else {
                $record = new \stdClass();
                $record->name = $modeldata['name'];
                $record->displayname = $modeldata['displayname'] ?? $modeldata['name'];
                $record->description = $modeldata['description'] ?? '';
                $record->mimetypes = $modeldata['mimetypes'] ?? '';
                $record->vision = (int) ($modeldata['vision'] ?? 0);
                $record->imggen = (int) ($modeldata['imggen'] ?? 0);
                $record->tts = (int) ($modeldata['tts'] ?? 0);
                $record->stt = (int) ($modeldata['stt'] ?? 0);
                $record->deprecated = (int) ($modeldata['deprecated'] ?? 0);
                $record->timecreated = $now;
                $record->timemodified = $now;
                $modelid = $DB->insert_record('local_ai_manager_model', $record);
            }

            // Insert connector assignments.
            if (!empty($modeldata['connectors'])) {
                foreach ($modeldata['connectors'] as $connector) {
                    if (
                        !$DB->record_exists('local_ai_manager_model_purpose', [
                        'modelid' => $modelid,
                        'connector' => $connector,
                        ])
                    ) {
                        $purposerecord = new \stdClass();
                        $purposerecord->modelid = $modelid;
                        $purposerecord->connector = $connector;
                        $purposerecord->timecreated = $now;
                        $purposerecord->timemodified = $now;
                        $DB->insert_record('local_ai_manager_model_purpose', $purposerecord);
                    }
                }
            }
        }
    }
}

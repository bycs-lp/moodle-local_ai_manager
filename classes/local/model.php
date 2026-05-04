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

use stdClass;

/**
 * Wrapper class for AI model records in the local_ai_manager_model table.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class model {
    /** @var ?stdClass The raw database record, null if not yet persisted. */
    private ?stdClass $record = null;

    /** @var int The record id. */
    private int $id = 0;

    /** @var ?string Unique model identifier string. */
    private ?string $name = null;

    /** @var ?string Human-readable display name. */
    private ?string $displayname = null;

    /** @var ?string Description of the model. */
    private ?string $description = null;

    /** @var ?string Comma-separated list of supported mimetypes. */
    private ?string $mimetypes = null;

    /** @var int Whether the model supports vision. */
    private int $vision = 0;

    /** @var int Whether the model supports image generation. */
    private int $imggen = 0;

    /** @var int Whether the model supports text-to-speech. */
    private int $tts = 0;

    /** @var int Whether the model supports speech-to-text. */
    private int $stt = 0;

    /** @var int Whether the model is deprecated. */
    private int $deprecated = 0;

    /**
     * Create a model object and optionally load an existing record from the database.
     *
     * @param int $id the record id, pass 0 to create a new model
     */
    public function __construct(int $id = 0) {
        $this->id = $id;
        if ($id > 0) {
            $this->load();
        }
    }

    /**
     * Loads the model data from the database.
     */
    public function load(): void {
        global $DB;

        $record = $DB->get_record('local_ai_manager_model', ['id' => $this->id]);
        if (!$record) {
            return;
        }
        $this->record = $record;
        $this->id = (int) $record->id;
        $this->name = $record->name;
        $this->displayname = $record->displayname;
        $this->description = $record->description;
        $this->mimetypes = $record->mimetypes;
        $this->vision = (int) $record->vision;
        $this->imggen = (int) $record->imggen;
        $this->tts = (int) $record->tts;
        $this->stt = (int) $record->stt;
        $this->deprecated = (int) $record->deprecated;
    }

    /**
     * Persists the model data to the database.
     */
    public function store(): void {
        global $DB;

        $clock = \core\di::get(\core\clock::class);
        $now = $clock->time();

        $record = new stdClass();
        $record->name = $this->name;
        $record->displayname = $this->displayname;
        $record->description = $this->description;
        $record->mimetypes = $this->mimetypes;
        $record->vision = $this->vision;
        $record->imggen = $this->imggen;
        $record->tts = $this->tts;
        $record->stt = $this->stt;
        $record->deprecated = $this->deprecated;
        $record->timemodified = $now;

        if (is_null($this->record)) {
            $record->timecreated = $now;
            $record->id = $DB->insert_record('local_ai_manager_model', $record);
            $this->id = (int) $record->id;
        } else {
            $record->id = $this->id;
            $DB->update_record('local_ai_manager_model', $record);
        }
        $this->record = $record;
    }

    /**
     * Deletes this model and all its connector assignments from the database.
     *
     * @throws \moodle_exception if the record does not exist
     */
    public function delete(): void {
        global $DB;

        if (empty($this->id)) {
            throw new \moodle_exception('exception_modelnotfound', 'local_ai_manager', '', '');
        }

        $DB->delete_records('local_ai_manager_model_purpose', ['modelid' => $this->id]);
        $DB->delete_records('local_ai_manager_model', ['id' => $this->id]);
        $this->record = null;
        $this->id = 0;
    }

    /**
     * Returns whether a database record exists for this model.
     *
     * @return bool true if a record exists in the database
     */
    public function record_exists(): bool {
        if (!is_null($this->record)) {
            return true;
        }
        $this->load();
        return !is_null($this->record);
    }

    /**
     * Returns the record id.
     *
     * @return int the model id
     */
    public function get_id(): int {
        return $this->id;
    }

    /**
     * Returns the unique model identifier string.
     *
     * @return ?string the model name or null if not set
     */
    public function get_name(): ?string {
        return $this->name;
    }

    /**
     * Sets the unique model identifier string.
     *
     * @param string $name the model name
     */
    public function set_name(string $name): void {
        $this->name = $name;
    }

    /**
     * Returns the human-readable display name.
     *
     * @return ?string the display name or null if not set
     */
    public function get_displayname(): ?string {
        return $this->displayname;
    }

    /**
     * Sets the human-readable display name.
     *
     * @param ?string $displayname the display name
     */
    public function set_displayname(?string $displayname): void {
        $this->displayname = $displayname;
    }

    /**
     * Returns the model description.
     *
     * @return ?string the description or null if not set
     */
    public function get_description(): ?string {
        return $this->description;
    }

    /**
     * Sets the model description.
     *
     * @param ?string $description the description
     */
    public function set_description(?string $description): void {
        $this->description = $description;
    }

    /**
     * Returns the comma-separated list of supported mimetypes.
     *
     * @return ?string the mimetypes string or null if not set
     */
    public function get_mimetypes(): ?string {
        return $this->mimetypes;
    }

    /**
     * Sets the comma-separated list of supported mimetypes.
     *
     * @param ?string $mimetypes the mimetypes string
     */
    public function set_mimetypes(?string $mimetypes): void {
        $this->mimetypes = $mimetypes;
    }

    /**
     * Returns whether the model supports vision.
     *
     * @return bool true if vision is supported
     */
    public function get_vision(): bool {
        return (bool) $this->vision;
    }

    /**
     * Sets whether the model supports vision.
     *
     * @param bool $vision true if vision is supported
     */
    public function set_vision(bool $vision): void {
        $this->vision = (int) $vision;
    }

    /**
     * Returns whether the model supports image generation.
     *
     * @return bool true if image generation is supported
     */
    public function get_imggen(): bool {
        return (bool) $this->imggen;
    }

    /**
     * Sets whether the model supports image generation.
     *
     * @param bool $imggen true if image generation is supported
     */
    public function set_imggen(bool $imggen): void {
        $this->imggen = (int) $imggen;
    }

    /**
     * Returns whether the model supports text-to-speech.
     *
     * @return bool true if TTS is supported
     */
    public function get_tts(): bool {
        return (bool) $this->tts;
    }

    /**
     * Sets whether the model supports text-to-speech.
     *
     * @param bool $tts true if TTS is supported
     */
    public function set_tts(bool $tts): void {
        $this->tts = (int) $tts;
    }

    /**
     * Returns whether the model supports speech-to-text.
     *
     * @return bool true if STT is supported
     */
    public function get_stt(): bool {
        return (bool) $this->stt;
    }

    /**
     * Sets whether the model supports speech-to-text.
     *
     * @param bool $stt true if STT is supported
     */
    public function set_stt(bool $stt): void {
        $this->stt = (int) $stt;
    }

    /**
     * Returns whether the model is deprecated.
     *
     * @return bool true if the model is deprecated
     */
    public function is_deprecated(): bool {
        return (bool) $this->deprecated;
    }

    /**
     * Sets whether the model is deprecated.
     *
     * @param bool $deprecated true to mark as deprecated
     */
    public function set_deprecated(bool $deprecated): void {
        $this->deprecated = (int) $deprecated;
    }

    /**
     * Returns the list of connector names assigned to this model.
     *
     * @return array list of connector name strings
     */
    public function get_connectors(): array {
        global $DB;

        if (empty($this->id)) {
            return [];
        }
        return $DB->get_fieldset('local_ai_manager_model_purpose', 'connector', ['modelid' => $this->id]);
    }

    /**
     * Assigns a connector to this model.
     *
     * If the connector is already assigned, this is a no-op.
     *
     * @param string $connector the connector plugin name (e.g. 'chatgpt', 'dalle')
     */
    public function add_connector(string $connector): void {
        global $DB;

        if (empty($this->id)) {
            throw new \coding_exception('Cannot add connector to a model that has not been stored yet.');
        }

        if ($DB->record_exists('local_ai_manager_model_purpose', ['modelid' => $this->id, 'connector' => $connector])) {
            return;
        }

        $clock = \core\di::get(\core\clock::class);
        $now = $clock->time();

        $record = new stdClass();
        $record->modelid = $this->id;
        $record->connector = $connector;
        $record->timecreated = $now;
        $record->timemodified = $now;
        $DB->insert_record('local_ai_manager_model_purpose', $record);
    }

    /**
     * Removes a connector assignment from this model.
     *
     * If the connector is not assigned, this is a no-op.
     *
     * @param string $connector the connector plugin name (e.g. 'chatgpt', 'dalle')
     */
    public function remove_connector(string $connector): void {
        global $DB;

        if (empty($this->id)) {
            return;
        }
        $DB->delete_records('local_ai_manager_model_purpose', ['modelid' => $this->id, 'connector' => $connector]);
    }

    /**
     * Loads a model by its unique name.
     *
     * @param string $name the model name
     * @return ?self the model object or null if not found
     */
    public static function get_by_name(string $name): ?self {
        global $DB;

        $id = $DB->get_field('local_ai_manager_model', 'id', ['name' => $name]);
        if (!$id) {
            return null;
        }
        return new self((int) $id);
    }

    /**
     * Returns all model objects, optionally filtered by connector and/or deprecation status.
     *
     * @param ?string $connector optional connector plugin name to filter by (e.g. 'chatgpt', 'gemini')
     * @param bool $includedeprecated whether to include deprecated models, defaults to true
     * @return array array of model objects
     */
    public static function get_all_models(?string $connector = null, bool $includedeprecated = true): array {
        global $DB;

        if (!is_null($connector)) {
            $sql = "SELECT m.id
                      FROM {local_ai_manager_model} m
                      JOIN {local_ai_manager_model_purpose} mp ON mp.modelid = m.id
                     WHERE mp.connector = :connector";
            $params = ['connector' => $connector];
            if (!$includedeprecated) {
                $sql .= " AND m.deprecated = :deprecated";
                $params['deprecated'] = 0;
            }
            $sql .= " ORDER BY m.name ASC";
            $records = $DB->get_records_sql($sql, $params);
        } else {
            $params = [];
            if (!$includedeprecated) {
                $params['deprecated'] = 0;
            }
            $records = $DB->get_records('local_ai_manager_model', $params, 'name ASC', 'id');
        }

        $models = [];
        foreach ($records as $record) {
            $models[] = new self((int) $record->id);
        }
        return $models;
    }
}

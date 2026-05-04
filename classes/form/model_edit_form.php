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

use context;
use context_system;
use core_form\dynamic_form;
use local_ai_manager\local\model;
use moodle_url;

/**
 * Dynamic form for creating/editing an AI model definition.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class model_edit_form extends dynamic_form {
    #[\Override]
    protected function definition() {
        $mform = $this->_form;

        $modelid = $this->optional_param('modelid', 0, PARAM_INT);
        $mform->addElement('hidden', 'modelid', $modelid);
        $mform->setType('modelid', PARAM_INT);

        $mform->addElement('text', 'name', get_string('model_name', 'local_ai_manager'));
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('name', 'model_name', 'local_ai_manager');

        $mform->addElement('text', 'displayname', get_string('model_displayname', 'local_ai_manager'));
        $mform->setType('displayname', PARAM_TEXT);

        $mform->addElement('textarea', 'description', get_string('description'), ['rows' => 3, 'cols' => 50]);
        $mform->setType('description', PARAM_TEXT);

        $mform->addElement(
            'textarea',
            'mimetypes',
            get_string('model_mimetypes', 'local_ai_manager'),
            ['rows' => 5, 'cols' => 50]
        );
        $mform->setType('mimetypes', PARAM_TEXT);
        $mform->addHelpButton('mimetypes', 'model_mimetypes', 'local_ai_manager');

        $mform->addElement('header', 'capabilitiesheader', get_string('model_capabilities', 'local_ai_manager'));

        $mform->addElement('advcheckbox', 'vision', get_string('model_vision', 'local_ai_manager'));
        $mform->setType('vision', PARAM_BOOL);

        $mform->addElement('advcheckbox', 'imggen', get_string('model_imggen', 'local_ai_manager'));
        $mform->setType('imggen', PARAM_BOOL);

        $mform->addElement('advcheckbox', 'tts', get_string('model_tts', 'local_ai_manager'));
        $mform->setType('tts', PARAM_BOOL);

        $mform->addElement('advcheckbox', 'stt', get_string('model_stt', 'local_ai_manager'));
        $mform->setType('stt', PARAM_BOOL);

        $mform->addElement('selectyesno', 'deprecated', get_string('model_deprecated', 'local_ai_manager'));

        $availableconnectors = array_keys(\core_plugin_manager::instance()->get_installed_plugins('aitool'));
        $connectoroptions = [];
        foreach ($availableconnectors as $connector) {
            $connectoroptions[$connector] = $connector;
        }
        $mform->addElement(
            'autocomplete',
            'connectors',
            get_string('model_connectors', 'local_ai_manager'),
            $connectoroptions,
            ['multiple' => true]
        );
    }

    #[\Override]
    protected function get_context_for_dynamic_submission(): context {
        return context_system::instance();
    }

    #[\Override]
    protected function check_access_for_dynamic_submission(): void {
        require_capability('local/ai_manager:managemodels', $this->get_context_for_dynamic_submission());
    }

    #[\Override]
    public function process_dynamic_submission(): array {
        $data = $this->get_data();
        $modelid = (int) $data->modelid;

        if ($modelid > 0) {
            $modelobj = new model($modelid);
        } else {
            $modelobj = new model();
        }

        $modelobj->set_name(trim($data->name));
        $modelobj->set_displayname(trim($data->displayname) ?: null);
        $modelobj->set_description(trim($data->description) ?: null);
        // Convert one-mimetype-per-line textarea to comma-separated string, trimming each entry.
        $mimetypesraw = $data->mimetypes ?? '';
        $mimetypelines = preg_split('/[\r\n,]+/', $mimetypesraw);
        $mimetypelines = array_map('trim', $mimetypelines);
        $mimetypelines = array_filter($mimetypelines, function ($v) {
            return $v !== '';
        });
        $modelobj->set_mimetypes(!empty($mimetypelines) ? implode(',', $mimetypelines) : null);
        $modelobj->set_vision((bool) $data->vision);
        $modelobj->set_imggen((bool) $data->imggen);
        $modelobj->set_tts((bool) $data->tts);
        $modelobj->set_stt((bool) $data->stt);
        $modelobj->set_deprecated((bool) $data->deprecated);
        $modelobj->store();

        // Sync connector assignments.
        $desiredconnectors = $data->connectors ?? [];
        $currentconnectors = $modelobj->get_connectors();

        // Add new ones.
        foreach ($desiredconnectors as $connector) {
            if (!in_array($connector, $currentconnectors)) {
                $modelobj->add_connector($connector);
            }
        }
        // Remove old ones.
        foreach ($currentconnectors as $connector) {
            if (!in_array($connector, $desiredconnectors)) {
                $modelobj->remove_connector($connector);
            }
        }

        return [];
    }

    #[\Override]
    public function set_data_for_dynamic_submission(): void {
        $modelid = $this->optional_param('modelid', 0, PARAM_INT);

        $data = ['modelid' => $modelid];

        if ($modelid > 0) {
            $modelobj = new model($modelid);
            $data['name'] = $modelobj->get_name();
            $data['displayname'] = $modelobj->get_displayname() ?? '';
            $data['description'] = $modelobj->get_description() ?? '';
            $data['mimetypes'] = implode("\n", array_map('trim', explode(',', $modelobj->get_mimetypes() ?? '')));
            $data['vision'] = (int) $modelobj->get_vision();
            $data['imggen'] = (int) $modelobj->get_imggen();
            $data['tts'] = (int) $modelobj->get_tts();
            $data['stt'] = (int) $modelobj->get_stt();
            $data['deprecated'] = (int) $modelobj->is_deprecated();
            $data['connectors'] = $modelobj->get_connectors();
        }

        $this->set_data($data);
    }

    #[\Override]
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/local/ai_manager/manage_models.php');
    }

    #[\Override]
    public function validation($data, $files): array {
        global $DB;

        $errors = parent::validation($data, $files);

        if (empty(trim($data['name'] ?? ''))) {
            $errors['name'] = get_string('required');
        } else {
            // Check for unique name.
            $existing = $DB->get_record('local_ai_manager_model', ['name' => trim($data['name'])]);
            if ($existing && (int) $existing->id !== (int) ($data['modelid'] ?? 0)) {
                $errors['name'] = get_string('model_name_exists', 'local_ai_manager');
            }
        }

        return $errors;
    }
}

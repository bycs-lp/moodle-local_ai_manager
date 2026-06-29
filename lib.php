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


// We're adding a mod_form extension for all activities so that they can indicate if they are
// going to take part in RAG (if it is enabled).


function local_ai_manager_coursemodule_edit_post_actions($data, $course) {
    global $DB, $USER;
    if (\aipurpose_rag\indexer_manager::is_rag_indexing_enabled()) {
       // RAG indexing is enabled.
       // A "falsey" value will cause the resource to be not indexed.
       // Only a "proper" truth-y value will cause the resource to be indexed.
       debugging('RAG indexing is enabled - processing allowindexing form field', DEBUG_DEVELOPER);
       $tx = $DB->start_delegated_transaction();
       $oldvalue = null;
       print_r($data);
       if ($cmconfig = \local_ai_manager\cmconfig::get_record(['cmid' => $data->id])) {
            $oldvalue = $cmconfig->get('intvalue');
            $cmconfig->set('intvalue', !empty($data->allowindexing) ? 1 : 0);
         } else {
              $record = new \stdClass();
              $record->cmid = $data->id;
              $record->intvalue = !empty($data->allowindexing) ? 1 : 0;
              $record->usermodified = $USER->id;
              $cmconfig = new \local_ai_manager\cmconfig(0, $record);
              print_r($cmconfig);
              $cmconfig->save();
       }
       $tx->allow_commit();
       if (!is_null($oldvalue)) {
            if ($oldvalue === 0 & $data->allowindexing) {
                // Turning off to on.
            } else if ($oldvalue === 1 & empty($data->allowindexing)) {
                // Turning on to off.
                // We should schedule a deindexing task.
            }
       } // Otherwise a new record, we don't care if it's changing state.
   }
    return $data;
}
function local_ai_manager_coursemodule_standard_elements($formwrapper, $mform) {

    if (\aipurpose_rag\indexer_manager::is_rag_indexing_enabled()) {
        $cmconfig = \local_ai_manager\cmconfig::get_record(['cmid' => $formwrapper->get_coursemodule()->id]);
        $mform->addElement('header', 'ragindexing', get_string('ragindexing', 'local_ai_manager'));
        $ynoptions = [0 => get_string('no'), 1 => get_string('yes')];
        $mform->addElement('select', 'allowindexing', get_string('allowindexing', 'local_ai_manager'), $ynoptions);
        if ($cmconfig && $cmconfig->get('intvalue') === 1) {
            $mform->setDefault('allowindexing', 1);
        } else {
            $mform->setDefault('allowindexing', 0);
        }
    }
}

function local_ai_manager_coursemodule_definition_after_data($formwrapper, $mform) {

}
function local_ai_manager_coursemodule_validation($fromform, $fields) {

}

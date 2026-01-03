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
//    if (\aipurpose_rag\indexer_manager::is_rag_indexing_enabled()) {
//        // RAG indexing is enabled.
//        // A "falsey" value will cause the resource to be not indexed.
//        // Only a "proper" truth-y value will cause the resource to be indexed.
//    }
}
function local_ai_manager_coursemodule_standard_elements($formwrapper, $mform) {

    if (\aipurpose_rag\indexer_manager::is_rag_indexing_enabled()) {
        $mform->addElement('header', 'ragindexing', get_string('ragindexing', 'local_ai_manager'));
        $ynoptions = [0 => get_string('no'), 1 => get_string('yes')];
        $mform->addElement('select', 'allowindexing', get_string('allowindexing', 'local_ai_manager'), $ynoptions);
        $mform->setDefault('allowindexing', 0);
    }
}

function local_ai_manager_coursemodule_definition_after_data($formwrapper, $mform) {

}
function local_ai_manager_coursemodule_validation($fromform, $fields) {

}

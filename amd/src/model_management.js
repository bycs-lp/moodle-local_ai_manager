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
 * JS module for managing AI model definitions.
 *
 * @module     local_ai_manager/model_management
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import ModalForm from 'core_form/modalform';
import {getString} from 'core/str';
import Notification from 'core/notification';

const SELECTORS = {
    ADD_BUTTON: '[data-action="addmodel"]',
    EDIT_BUTTON: '[data-action="edit"]',
    DELETE_BUTTON: '[data-action="delete"]',
};

const FORM_CLASS = 'local_ai_manager\\form\\model_edit_form';

/**
 * Initialise event listeners for the model management table.
 */
export const init = () => {
    registerListeners();
};

/**
 * Open the model edit modal form.
 *
 * @param {number} modelid The model id (0 for new model, >0 for editing an existing one)
 */
const openModelForm = (modelid) => {
    const title = modelid
        ? getString('model_edit', 'local_ai_manager')
        : getString('model_add', 'local_ai_manager');

    const modalForm = new ModalForm({
        formClass: FORM_CLASS,
        args: {modelid},
        modalConfig: {title},
    });
    modalForm.addEventListener(modalForm.events.FORM_SUBMITTED, () => window.location.reload());

    modalForm.show();
};

/**
 * Delete a model after user confirmation.
 *
 * @param {number} modelid The model id to delete
 * @param {string} modelname The model name for the confirm message
 */
const deleteModelWithConfirmation = async(modelid, modelname) => {
    try {
        const confirmMessage = await getString('model_delete_confirm', 'local_ai_manager', modelname);
        const confirmTitle = await getString('model_delete', 'local_ai_manager');
        const deleteLabel = await getString('delete');

        await Notification.saveCancelPromise(confirmTitle, confirmMessage, deleteLabel);
        await Ajax.call([{
            methodname: 'local_ai_manager_delete_model',
            args: {modelid},
        }])[0];
        window.location.reload();
    } catch (error) {
        // The saveCancelPromise rejects with a jQuery event on cancel/hidden.
        if (!error?.type) {
            Notification.exception(error);
        }
    }
};

/**
 * Register all click listeners for the model management page.
 */
const registerListeners = () => {
    // Use event delegation on the document body to handle dynamically rendered table content.
    document.body.addEventListener('click', (e) => {
        const addButton = e.target.closest(SELECTORS.ADD_BUTTON);
        if (addButton) {
            e.preventDefault();
            openModelForm(0);
            return;
        }

        const editButton = e.target.closest(SELECTORS.EDIT_BUTTON);
        if (editButton) {
            e.preventDefault();
            const modelid = parseInt(editButton.dataset.modelid);
            openModelForm(modelid);
            return;
        }

        const deleteButton = e.target.closest(SELECTORS.DELETE_BUTTON);
        if (deleteButton) {
            e.preventDefault();
            const modelid = parseInt(deleteButton.dataset.modelid);
            const modelname = deleteButton.dataset.modelname || '';
            deleteModelWithConfirmation(modelid, modelname);
        }
    });
};

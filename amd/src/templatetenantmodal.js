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
 * Module showing the configuration of the template tenant in a modal.
 *
 * @module     local_ai_manager/templatetenantmodal
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getString} from 'core/str';
import Modal from 'core/modal';

/**
 * Initializes the button opening the template tenant modal.
 *
 * @param {string} instanceTableSelector the selector of the instance table containing the button and the modal content
 */
export const init = (instanceTableSelector) => {
    const instanceTable = document.querySelector(instanceTableSelector);
    const modalContent = instanceTable.querySelector('[data-region="local_ai_manager-templatetenantmodal"]');
    let modal = null;
    instanceTable.querySelector('[data-action="local_ai_manager-showtemplatetenant"]').addEventListener('click', async() => {
        if (!modal) {
            modal = await Modal.create({
                title: getString('templatetenantmodal_heading', 'local_ai_manager'),
                body: modalContent.innerHTML,
                large: true,
            });
        }
        modal.show();
    });
};

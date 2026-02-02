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
 * Toggle handler.
 *
 * @module      local_ai_manager/toggle_handler
 * @copyright   2024 ISB Bayern
 * @author      Philipp Memmel
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

let controllers = {};

export const init = (inputSelector) => {
    const toggleContainer = document.querySelector(inputSelector);
    const identifier = toggleContainer.dataset.toggleIdentifier;
    if (toggleContainer) {
        const toggle = toggleContainer.querySelector('input');
        if (controllers.hasOwnProperty(identifier)) {
            controllers[identifier].abort();
        }
        controllers[identifier] = new AbortController();
        const {signal} = controllers[identifier];
        toggleContainer.addEventListener('click', () => {
            // Click event will fire before status is being updated, so we have to invert 0 and 1 here.
            toggleContainer.dataset.checked = toggle.checked ? '0' : '1';
            toggle.checked = !toggle.checked;
            toggle.dispatchEvent(new Event('change'));
        }, {signal});

        // To make the toggle also usable without directly loading a page on changing the state
        // we only add the listener here if both target attributes are set and not empty.
        const useUrlsOnChange = toggle.dataset.targetwhenchecked && toggle.dataset.targetwhenchecked.length > 0
            && toggle.dataset.targetwhennotchecked && toggle.dataset.targetwhennotchecked.length > 0;
        if (useUrlsOnChange) {
            toggle.addEventListener('change', () => {
                // New state incoming.
                if (!toggle.checked) {
                    window.location.replace(toggle.dataset.targetwhenchecked);
                } else {
                    window.location.replace(toggle.dataset.targetwhennotchecked);
                }
                return false;
            });
        }
    }
};



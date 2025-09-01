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

use local_ai_manager\local\userinfo;

defined('MOODLE_INTERNAL') || die;

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Rights config form.
 *
 * This form handles the user locking/unlocking, assigning of roles etc. on the rights config page.
 *
 * @package    local_ai_manager
 * @copyright  2024 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rights_config_form extends \moodleform {

    /** @var string Constant for defining the action "assign role". */
    const ACTION_ASSIGN_ROLE = 'assignrole';

    /** @var string Constant for defining the action "change lock status". */
    const ACTION_CHANGE_LOCK_STATE = 'changelockstate';
    /** @var string Constant for defining the action option "locked" for the action {@see self::ACTION_CHANGE_LOCK_STATE}. */
    const ACTIONOPTION_CHANGE_LOCK_STATE_LOCKED = 'locked';
    /** @var string Constant for defining the action option "unlocked" for the action {@see self::ACTION_CHANGE_LOCK_STATE}. */
    const ACTIONOPTION_CHANGE_LOCK_STATE_UNLOCKED = 'unlocked';

    /** @var string Constant for defining the action "change confirm status of terms of use". */
    const ACTION_CHANGE_CONFIRM_STATE = 'changeconfirmstate';
    /** @var string Constant for defining the action option "confirm" for the action {@see self::ACTION_CHANGE_CONFIRM_STATUS}. */
    const ACTIONOPTION_CHANGE_CONFIRM_STATE_CONFIRM = 'confirm';
    /** @var string Constant for defining the action option "confirm" for the action {@see self::ACTION_CHANGE_CONFIRM_STATUS}. */
    const ACTIONOPTION_CHANGE_CONFIRM_STATE_UNCONFIRM = 'unconfirm';

    /** @var string Constant for defining the action "change usage scope". */
    const ACTION_CHANGE_SCOPE = 'changescope';

    /**
     * Form definition.
     */
    public function definition() {
        $tenant = \core\di::get(\local_ai_manager\local\tenant::class);
        $mform = &$this->_form;

        $mform->addElement('hidden', 'tenant', $tenant->get_identifier());
        $mform->setType('tenant', PARAM_ALPHANUM);

        $mform->addElement('hidden', 'userids', '', ['id' => 'rights-table-userids']);
        $mform->setType('userids', PARAM_TEXT);

        $actionoptions = [
                self::ACTION_ASSIGN_ROLE => get_string('assignrole', 'local_ai_manager'),
                self::ACTION_CHANGE_LOCK_STATE => get_string('changelockstate', 'local_ai_manager'),
                self::ACTION_CHANGE_SCOPE => get_string('changescope', 'local_ai_manager'),
        ];
        if (!empty(get_config('local_ai_manager', 'requireconfirmtou'))) {
            $actionoptions[self::ACTION_CHANGE_CONFIRM_STATE] = get_string('changeconfirmstate', 'local_ai_manager');
        }

        $actionselectsgroup[] = $mform->createElement('select', 'action', '', $actionoptions);

        $actionselectsgroup[] = $mform->createElement('select', 'role', '', [
                userinfo::ROLE_BASIC => get_string(userinfo::get_role_as_string(userinfo::ROLE_BASIC), 'local_ai_manager'),
                userinfo::ROLE_EXTENDED => get_string(userinfo::get_role_as_string(userinfo::ROLE_EXTENDED), 'local_ai_manager'),
                userinfo::ROLE_UNLIMITED => get_string(userinfo::get_role_as_string(userinfo::ROLE_UNLIMITED), 'local_ai_manager'),
                userinfo::ROLE_DEFAULT => get_string('defaultrole', 'local_ai_manager'),
        ]);
        $mform->hideif('role', 'action', 'neq', self::ACTION_ASSIGN_ROLE);

        $actionselectsgroup[] = $mform->createElement('select', 'lockstate', '',
                [
                        self::ACTIONOPTION_CHANGE_LOCK_STATE_LOCKED => get_string('lock', 'local_ai_manager'),
                        self::ACTIONOPTION_CHANGE_LOCK_STATE_UNLOCKED => get_string('unlock', 'local_ai_manager'),
                ]
        );
        $mform->hideif('lockstate', 'action', 'neq', self::ACTION_CHANGE_LOCK_STATE);

        if (!empty(get_config('local_ai_manager', 'requireconfirmtou'))) {
            $actionselectsgroup[] = $mform->createElement('select', 'confirmstate', '',
                    [
                            self::ACTIONOPTION_CHANGE_CONFIRM_STATE_CONFIRM => get_string('confirmed', 'local_ai_manager'),
                            self::ACTIONOPTION_CHANGE_CONFIRM_STATE_UNCONFIRM => get_string('unconfirmed', 'local_ai_manager'),
                    ]
            );
            $mform->hideif('confirmstate', 'action', 'neq', self::ACTION_CHANGE_CONFIRM_STATE);
        }

        $actionselectsgroup[] = $mform->createElement('select', 'scope', '',
                [
                        userinfo::SCOPE_COURSES_ONLY => get_string('scope_courses', 'local_ai_manager'),
                        userinfo::SCOPE_EVERYWHERE => get_string('scope_everywhere', 'local_ai_manager'),
                ]
        );
        $mform->hideif('scope', 'action', 'neq', self::ACTION_CHANGE_SCOPE);

        $mform->addGroup($actionselectsgroup, 'actiongroup', get_string('executebulkuseractions', 'local_ai_manager') . ':', [' '],
                false);

        $buttonarray = [];
        $buttonarray[] = $mform->createElement('submit', 'executeaction', get_string('executeaction', 'local_ai_manager'));
        $buttonarray[] = $mform->createElement('cancel');
        $mform->addGroup($buttonarray, 'buttonar', '', [' '], false);
        $mform->closeHeaderBefore('buttonar');
    }
}

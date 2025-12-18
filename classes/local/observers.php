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

use core\event\user_deleted;

/**
 * Observer functions for local_ai_manager.
 *
 * @package   local_ai_manager
 * @copyright 2025 ISB Bayern
 * @author    Philipp Memmel
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observers {
    /**
     * Observer for user_deleted event.
     *
     * This will wipe the userinfo record and the userusage records for the user.
     *
     * @param user_deleted $event The user deleted event
     */
    public static function user_deleted(user_deleted $event): void {
        $datawiper = new data_wiper();
        $datawiper->delete_userinfo($event->objectid);
        $datawiper->delete_userusage($event->objectid);
        $datawiper->anonymize_request_log_for_user($event->objectid, true);
    }
}

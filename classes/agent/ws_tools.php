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

/**
 * Declarative registration of WS-backed tool adapters (MBS-10761).
 *
 * Core adapters on top of existing Moodle external-service functions.
 * Third-party plugins SHOULD NOT modify this file; they register their own
 * adapters via db/agenttools.php (SPEZ §18).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Baustein 6 will populate this list with concrete adapters for
// core_enrol_external::get_enrolled_users etc. Kept empty here so the
// registry can scan it without errors.
$wstools = [];

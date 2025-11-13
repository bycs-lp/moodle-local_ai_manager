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
 * Version file for aipurpose_stt.
 *
 * @package    aipurpose_stt
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2025102900;        // YYYYMMDDXX format.
$plugin->requires  = 2023042403;        // Moodle 4.2+ required.
$plugin->release   = '1.0.0';
$plugin->component = 'aipurpose_stt';
$plugin->maturity  = MATURITY_ALPHA;
$plugin->dependencies = [
    'local_ai_manager' => 2023042403,   // Requires local_ai_manager.
];

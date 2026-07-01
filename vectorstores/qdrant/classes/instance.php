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

namespace aivecstore_qdrant;

use local_ai_manager\base_vecstore_instance;

/**
 * Vector store instance class for the Qdrant backend.
 *
 * The connection configuration for Qdrant is fully covered by the shared fields defined in
 * {@see base_vecstore_instance}. Backend-specific form fields can be added here by overriding the
 * extend_* hooks if needed in the future.
 *
 * @package    aivecstore_qdrant
 * @copyright  2026 Exputo Inc.
 * @author     David Pesce <david.pesce@exputo.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class instance extends base_vecstore_instance {
}

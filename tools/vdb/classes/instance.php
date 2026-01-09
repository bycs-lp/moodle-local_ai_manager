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

namespace aitool_vdb;

use local_ai_manager\base_instance;

/**
 * Instance class for vector database connector instances.
 *
 * This class provides VDB-specific configuration and form handling
 * for vector database AI tool instances.
 *
 * @package    aitool_vdb
 * @copyright  2025 University of Strathclyde
 * @author     Michael Hughes
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class instance extends base_instance {

    #[\Override]
    protected function extend_form_definition(\MoodleQuickForm $mform): void {
        // Add VDB-specific form fields if needed
        // For now, we'll rely on the base instance configuration
        // Subclasses can override this to add specific fields
    }
}
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

namespace local_ai_manager\table;

use core_table\local\filter\integer_filter;
use core_table\local\filter\string_filter;

/**
 * Filterset for the model management table.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class model_management_table_filterset extends \core_table\local\filter\filterset {
    /**
     * Get the optional filters.
     *
     * @return array
     */
    public function get_optional_filters(): array {
        return [
            'namepattern' => string_filter::class,
            'connector' => string_filter::class,
            'deprecated' => integer_filter::class,
        ];
    }
}

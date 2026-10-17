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

namespace local_ai_manager\tests;

use local_ai_manager\base_purpose;

/**
 * Purpose for testing with an array option "items" which rejects the item "invalid" in its option validation.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class array_option_purpose extends base_purpose {
    #[\Override]
    public function get_additional_purpose_options(): array {
        return ['items' => base_purpose::PARAM_ARRAY];
    }

    #[\Override]
    public function validate_purpose_options(array $options): void {
        if (in_array('invalid', $options['items'] ?? [], true)) {
            throw new \coding_exception('Invalid item');
        }
    }
}

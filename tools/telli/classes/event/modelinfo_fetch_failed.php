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

namespace aitool_telli\event;

/**
 * Event triggered when the model information could not be fetched from the Telli API.
 *
 * @package    aitool_telli
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class modelinfo_fetch_failed extends \core\event\base {
    #[\Override]
    protected function init(): void {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    #[\Override]
    public static function get_name(): string {
        return get_string('eventmodelinfofetchfailed', 'aitool_telli');
    }

    #[\Override]
    public function get_description(): string {
        return "The model information could not be fetched from the Telli API with the base URL '{$this->other['baseurl']}': "
            . $this->other['errormessage'];
    }

    #[\Override]
    protected function validate_data(): void {
        parent::validate_data();
        foreach (['baseurl', 'errormessage'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("The '{$key}' value must be set in other.");
            }
        }
    }
}

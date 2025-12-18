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

use moodle_url;

/**
 * Subplugin overview table.
 *
 * @package    local_ai_manager
 * @copyright  2025 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class aitools_admin_table extends \core_admin\table\plugin_management_table {
    #[\Override]
    protected function get_plugintype(): string {
        return 'aitool';
    }

    #[\Override]
    public function guess_base_url(): void {
        $this->define_baseurl(
            new moodle_url('/admin/settings.php', ['section' => 'aitoolpluginsmanagement'])
        );
    }

    #[\Override]
    protected function get_action_url(array $params = []): moodle_url {
        // We do not want to add code that is only relevant in case we do not have JS,
        // so we fake this implementation.
        return new moodle_url('/');
    }

    #[\Override]
    protected function supports_disabling(): bool {
        return true;
    }
}

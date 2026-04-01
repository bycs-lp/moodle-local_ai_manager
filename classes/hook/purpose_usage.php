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

namespace local_ai_manager\hook;

use core_plugin_manager;
use local_ai_manager\local\connector_factory;

/**
 * Hook that allows other plugins to specify which purposes they are using and where.
 *
 * The information collected by this hook is being used to inform the user which purposes are used by which plugins.
 *
 * @package    local_ai_manager
 * @copyright  2025 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to add information about which purpose they are using and where exactly.')]
#[\core\attribute\tags('local_ai_manager')]
class purpose_usage {
    /**
     * @var string[] array with the localized display names of the components, indexed by component name.
     *
     * It is of the form ['block_ai_chat' => 'AI chatbot', 'tiny_ai' => 'Editor utils', ...].
     */
    private array $componentdisplaynames = [];

    /** @var array Contains the usage information that is being collected by this hook. */
    private array $purposeusage = [];

    /**
     * Getter for the component/plugin display name.
     *
     * @param string $component The component for which the display name should be retrieved
     * @return string The localized display name of the component
     */
    public function get_component_displayname(string $component): string {
        return isset($this->componentdisplaynames[$component]) && !empty($this->componentdisplaynames[$component])
                ? $this->componentdisplaynames[$component]
                : get_string('pluginname', $component);
    }

    /**
     * Setter for the component/plugin display name.
     *
     * @param string $component The component for which a display name should be set
     * @param string $displayname The localized display name of the component
     */
    public function set_component_displayname(string $component, string $displayname): void {
        $this->componentdisplaynames[$component] = $displayname;
    }

    /**
     * Getter and formatter for the purpose usage array.
     *
     * This array will be given in a form suitable for using as a template context.
     *
     * @return array The purpose usage array
     */
    public function get_purposes_usage_info(): array {
        return $this->purposeusage;
    }

    /**
     * Method for usage of the hook listener to inject the information about the plugin.
     *
     * This method should be called from inside the hook listener to provide the information about which purpose is being used
     * by the plugin and where. It can be called multiple times in a hook listener if multiple purposes are used and also if
     * the plugin wants to provide multiple places (and thus place descriptions) for the same purpose.
     *
     * @param string $purposestring the string identifying the purpose
     * @param string $component the component of the plugin that wants to provide the information (most times the component name
     *  of the plugin of hook listener that calls this method)
     * @param string $placedescription the localized, user-faced description in which place the plugin uses this purpose
     * @throws \coding_exception if the purpose does not exist
     */
    public function add_purpose_usage_description(string $purposestring, string $component, string $placedescription): void {
        if (!in_array($purposestring, array_keys(core_plugin_manager::instance()->get_installed_plugins('aipurpose')))) {
            throw new \coding_exception('The purpose "' . $purposestring . '" is not a valid purpose.');
        }
        if (!isset($this->purposeusage[$purposestring])) {
            $this->purposeusage[$purposestring] = [];
        }
        $this->purposeusage[$purposestring][$component][] = $placedescription;
    }
}

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

use local_ai_manager\base_vecstore_instance;

/**
 * Hook collecting which data of other plugins depends on a vector store instance.
 *
 * Dispatched before a vector store instance is deleted or stops being the primary vector store, so the user can be
 * warned about the affected data before confirming.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to report which of their data depends on a vector store instance.')]
#[\core\attribute\tags('local_ai_manager')]
class vecstore_usage {
    /** @var string[][] Localized usage descriptions, indexed by component. */
    private array $usagedescriptions = [];

    /**
     * Constructor.
     *
     * @param base_vecstore_instance $instance The vector store instance the usage is collected for.
     * @param bool $deleting True if the instance is about to be deleted, false if it stops being the primary one.
     */
    public function __construct(
        /** @var base_vecstore_instance The vector store instance the usage is collected for. */
        public readonly base_vecstore_instance $instance,
        /** @var bool True if the instance is about to be deleted, false if it stops being the primary one. */
        public readonly bool $deleting,
    ) {
    }

    /**
     * Report data of a component depending on the vector store instance.
     *
     * @param string $component The component reporting the usage.
     * @param string $description Localized description of the affected data, including what will happen to it.
     */
    public function add_usage_description(string $component, string $description): void {
        $this->usagedescriptions[$component][] = $description;
    }

    /**
     * Get the collected usage descriptions.
     *
     * @return string[][] Localized usage descriptions, indexed by component.
     */
    public function get_usage_descriptions(): array {
        return $this->usagedescriptions;
    }
}

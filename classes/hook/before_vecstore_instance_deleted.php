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
 * Hook dispatched before a vector store instance is deleted.
 *
 * The instance is still usable, so listeners can delete their vectors from it and detach their data.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to detach their data before a vector store instance is deleted.')]
#[\core\attribute\tags('local_ai_manager')]
class before_vecstore_instance_deleted {
    /**
     * Constructor.
     *
     * @param base_vecstore_instance $instance The vector store instance being deleted.
     * @param bool $deletevectors Whether the user chose to delete the stored vectors.
     */
    public function __construct(
        /** @var base_vecstore_instance The vector store instance being deleted. */
        public readonly base_vecstore_instance $instance,
        /** @var bool Whether the user chose to delete the stored vectors. */
        public readonly bool $deletevectors,
    ) {
    }
}

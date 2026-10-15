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
 * Hook dispatched before another vector store instance becomes the primary vector store of a tenant.
 *
 * Listeners detach their data from the old primary instance, so it is indexed again in the new one.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to detach their data from the old primary vector store instance.')]
#[\core\attribute\tags('local_ai_manager')]
class before_primary_vecstore_changed {
    /**
     * Constructor.
     *
     * @param base_vecstore_instance $oldprimary The current primary vector store instance.
     * @param base_vecstore_instance $newprimary The vector store instance becoming the primary one.
     * @param bool $deletevectors Whether the user chose to delete the stored vectors from the old primary instance.
     */
    public function __construct(
        /** @var base_vecstore_instance The current primary vector store instance. */
        public readonly base_vecstore_instance $oldprimary,
        /** @var base_vecstore_instance The vector store instance becoming the primary one. */
        public readonly base_vecstore_instance $newprimary,
        /** @var bool Whether the user chose to delete the stored vectors from the old primary instance. */
        public readonly bool $deletevectors,
    ) {
    }
}

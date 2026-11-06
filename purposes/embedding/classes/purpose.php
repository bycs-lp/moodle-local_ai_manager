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

/**
 * Embedding purpose methods.
 *
 * @package    aipurpose_embedding
 * @copyright  2026 ISB Bayern
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aipurpose_embedding;

use local_ai_manager\base_purpose;

/**
 * Embedding purpose methods.
 *
 * @package    aipurpose_embedding
 * @copyright  2026 ISB Bayern
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purpose extends base_purpose {
    /**
     * Returns the raw output unchanged.
     *
     * The embedding connector returns a JSON encoded vector, not Markdown text. We must not run it
     * through the default Markdown/HTML formatting, otherwise the JSON gets mangled and is no longer
     * decodable.
     *
     * @param string $output the JSON encoded embedding vector returned by the connector
     * @return string the unmodified output
     */
    #[\Override]
    public function format_output(string $output): string {
        return $output;
    }

    /**
     * Sources are indexed with the embedding model of the user who starts the indexing, and they can only be searched with
     * the same embedding model. So all users of a tenant have to use the same embedding model.
     *
     * @return bool always true
     */
    #[\Override]
    public function requires_same_model_for_all_roles(): bool {
        return true;
    }
}



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

namespace local_ai_manager\local;

use local_ai_content\local\enriched_vector;

/**
 * Data object class for storing vecstore query result information in a defined way.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class vecstore_query_response {
    /** @var enriched_vector[] The query matches returned by the vecstore backend. */
    private array $matches;

    /**
     * Private constructor to avoid object creation without static create function.
     */
    private function __construct() {
    }

    /**
     * Standard setter.
     *
     * @param enriched_vector[] $matches the query matches
     */
    public function set_matches(array $matches): void {
        $this->matches = $matches;
    }

    /**
     * Standard getter.
     *
     * @return enriched_vector[] the query matches
     */
    public function get_matches(): array {
        return $this->matches;
    }

    /**
     * Static create function for a vecstore_query_response object in case of a successful query response.
     *
     * @param enriched_vector[] $matches the query matches
     * @return vecstore_query_response the vecstore_query_response object containing the query matches
     */
    public static function create_from_result(array $matches): vecstore_query_response {
        $queryresponse = new self();
        $queryresponse->set_matches($matches);
        return $queryresponse;
    }

    /**
     * Static create function for a vecstore_query_response object with no matches.
     *
     * @return vecstore_query_response the vecstore_query_response object containing no query matches
     */
    public static function create_from_empty_result(): vecstore_query_response {
        $queryresponse = new self();
        $queryresponse->set_matches([]);
        return $queryresponse;
    }
}


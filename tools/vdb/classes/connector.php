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

namespace aitool_vdb;

use local_ai_manager\local\request_response;
use local_ai_manager\request_options;

/**
 * Default connector implementation for the abstract VDB plugin.
 *
 * This is a placeholder implementation that throws exceptions,
 * as this plugin is meant to be abstract and extended by specific VDB implementations.
 *
 * @package    aitool_vdb
 * @copyright  2025 University of Strathclyde
 * @author     Michael Hughes
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class connector extends base_vdb_connector {

    #[\Override]
    protected function get_supported_embedding_models(): array {
        // This is an abstract plugin, so it doesn't support any models directly
        return [];
    }

    #[\Override]
    protected function make_vdb_request(array $data, request_options $requestoptions, string $action): request_response {
        // This is an abstract plugin and should not be used directly
        return request_response::create_from_error(
            501,
            get_string('error_abstract_plugin', 'aitool_vdb'),
            'The VDB plugin is abstract and cannot be used directly. Please use a concrete implementation like Qdrant.'
        );
    }

    #[\Override]
    protected function get_embedding(string $content): array {
        // This is an abstract plugin and should not be used directly
        throw new \Exception('The VDB plugin is abstract and cannot be used directly');
    }

    #[\Override]
    protected function get_collection_name(): string {
        // Return a default collection name
        return 'moodle_abstract';
    }

    #[\Override]
    protected function get_vector_field_name(): string {
        // Return a default vector field name
        return 'vector';
    }
}
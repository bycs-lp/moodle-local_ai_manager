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

use local_ai_manager\base_connector;
use local_ai_manager\local\prompt_response;
use local_ai_manager\local\request_response;
use local_ai_manager\local\unit;
use local_ai_manager\request_options;
use Psr\Http\Message\StreamInterface;

/**
 * Abstract base class for vector database connectors.
 *
 * This class provides a common interface for vector database operations
 * while extending the standard AI tool connector functionality.
 * 
 * I'd also like to add an implementation here that when the connector is disposed of, it will 
 * write a log message somewhere if the guardrails weren't run. This is to help catch
 * mis-implementations of connectors that forget to call the guardrails.
 *
 * @package    aitool_vdb
 * @copyright  2025 University of Strathclyde
 * @author     Michael Hughes
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base_vdb_connector extends base_connector {

    /**
     * Vector database operations supported by VDB connectors.
     */
    public const VDB_ACTION_STORE = 'store';
    public const VDB_ACTION_RETRIEVE = 'retrieve';
    public const VDB_ACTION_DELETE = 'delete';
    public const VDB_ACTION_UPDATE = 'update';

    /**
     * Flag to indicate if result has been sanitised.
     * 
     * This means to check that the *Moodle* rights have been checked,
     * not the data entry.
     */
    private $sanitised = false;

    #[\Override]
    public function get_models_by_purpose(): array {
        // VDB tools primarily support RAG purposes
        return [
            'rag' => $this->get_supported_embedding_models()
        ];
    }

    #[\Override]
    public function get_unit(): unit {
        return unit::COUNT;
    }

    #[\Override]
    public function make_request(array $data, request_options $requestoptions): request_response {
        $action = $requestoptions->get_options()['action'] ?? self::VDB_ACTION_RETRIEVE;
        
        // Validate the action is supported
        if (!$this->is_action_supported($action)) {
            return request_response::create_from_error(
                400,
                get_string('error_unsupported_action', 'aitool_vdb', $action),
                "Action '{$action}' is not supported by this VDB connector"
            );
        }

        return $this->make_vdb_request($data, $requestoptions, $action);
    }
    /**
     * Conform the contents of the response to a standard format.
     */
    protected function conform_contents(string $content, request_options $requestoptions): string {
        // Default implementation does nothing, override in subclasses if needed
        return $content;
    }
    #[\Override]
    public function execute_prompt_completion(StreamInterface $result, request_options $requestoptions): prompt_response {
        $content = $result->getContents();  // Pass this to underlying implementation to convert to a conformed response.
        $content = json_decode($content, true);
        // We'll assume that we get a JSON string back, so we need to convert to a useful object/array.
        if ($content === null) {
            return prompt_response::create_from_error(
                500,
                get_string('error_invalid_response', 'aitool_vdb'),
                'The response from the VDB was not valid JSON'
            );
        }
        $content = $this->conform_contents($content, $requestoptions);
        $content = $this->apply_guard_rails($content, $requestoptions);
        return prompt_response::create_from_result(
            $this->instance->get_model(),
            new \local_ai_manager\local\usage(1.0),
            $content
        );
    }

    #[\Override]
    public function get_prompt_data(string $prompttext, request_options $requestoptions): array {
        $action = $requestoptions->get_options()['action'] ?? self::VDB_ACTION_RETRIEVE;
        
        $prompt = [
            'action' => $action,
            'content' => $prompttext,
        ];

        // Add action-specific parameters
        switch ($action) {
            case self::VDB_ACTION_RETRIEVE:
                $prompt['topk'] = $requestoptions->get_options()['topk'] ?? 1;
                break;
            case self::VDB_ACTION_STORE:
                $prompt['document'] = $requestoptions->get_options()['document'] ?? [];
                $prompt['metadata'] = $requestoptions->get_options()['metadata'] ?? [];
                break;
            case self::VDB_ACTION_DELETE:
            case self::VDB_ACTION_UPDATE:
                $prompt['id'] = $requestoptions->get_options()['id'] ?? null;
                break;
        }

        return $prompt;
    }

    /**
     * Apply guard rails to the content.
     */
    protected function apply_guard_rails($content, request_options $requestoptions) {
        $debug = false;
        if ($debug) {
            $debuggingcontent = \html_writer::tag('h3', 'Guard Rails Debugging' );
            $debuggingcontent .= \html_writer::tag('h4', 'Input content' );
            $debuggingcontent .= \html_writer::tag('pre', print_r($content, true));
            $debuggingcontent .= \html_writer::tag('pre', print_r($requestoptions, true));
        }

        $this->sanitised = true;
        if ($debug) {
            $debuggingcontent .= \html_writer::tag('h4', 'Output content' );
            $debuggingcontent .= \html_writer::tag('pre', print_r($content, true));
            $debuggingcontent .= \html_writer::tag('pre', print_r($requestoptions, true));
            echo \html_writer::div($debuggingcontent, 'debuggingcontent');
        }

        return $content;
    }

    /**
     * Get the list of embedding models supported by this VDB connector.
     *
     * @return array Array of supported embedding model names
     */
    abstract protected function get_supported_embedding_models(): array;

    /**
     * Make a vector database specific request.
     *
     * @param array $data The data to send with the request
     * @param request_options $requestoptions The request options
     * @param string $action The VDB action to perform
     * @return request_response The response from the request
     */
    abstract protected function make_vdb_request(array $data, request_options $requestoptions, string $action): request_response;

    /**
     * Check if the given action is supported by this VDB connector.
     *
     * @param string $action The action to check
     * @return bool True if the action is supported
     */
    protected function is_action_supported(string $action): bool {
        return in_array($action, $this->get_supported_actions());
    }

    /**
     * Get the list of actions supported by this VDB connector.
     *
     * @return array Array of supported action names
     */
    protected function get_supported_actions(): array {
        return [
            self::VDB_ACTION_STORE,
            self::VDB_ACTION_RETRIEVE,
        ];
    }

    /**
     * Get embedding for text content.
     *
     * This method should handle embedding generation, potentially with caching.
     *
     * @param string $content The text content to embed
     * @return array The embedding vector as an array of floats
     */
    abstract protected function get_embedding(string $content): array;

    /**
     * Get the collection/index name for storing vectors.
     *
     * @return string The collection name
     */
    abstract protected function get_collection_name(): string;

    /**
     * Get the vector field name used in the database.
     *
     * @return string The vector field name
     */
    abstract protected function get_vector_field_name(): string;

    /**
     * Generate a unique identifier for vector storage.
     *
     * @return string A unique identifier
     */
    protected function generate_vector_id(): string {
        return uniqid('vdb_', true);
    }

    /**
     * Get available VDB-specific options for configuration.
     *
     * @return array Array of option definitions
     */
    #[\Override]
    public function get_available_options(): array {
        return [
            'topk' => [
                'type' => 'int',
                'default' => 5,
                'description' => get_string('option_topk_desc', 'aitool_vdb'),
            ],
            'collection_name' => [
                'type' => 'text',
                'default' => 'moodle',
                'description' => get_string('option_collection_name_desc', 'aitool_vdb'),
            ],
        ];
    }
}
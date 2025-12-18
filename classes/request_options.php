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

namespace local_ai_manager;

use coding_exception;
use context;

/**
 * Wrapper for handling of submitted request options.
 *
 * @package    local_ai_manager
 * @copyright  2024 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class request_options {
    /**
     * Create the request_options object.
     */
    public function __construct(
        /** @var base_purpose The purpose the request should use */
        private readonly base_purpose $purpose,
        /** @var context The context in which the request is being performed */
        private readonly context $context,
        /** @var string The name of the component from which the request is being performed */
        private readonly string $component,
        /** @var array additional request options for the AI request */
        private array $options = []
    ) {
        $this->options = $this->purpose->get_request_options($options);
    }

    /**
     * The purpose being used in this request.
     *
     * @return base_purpose the purpose object
     */
    public function get_purpose(): base_purpose {
        return $this->purpose;
    }

    /**
     * The context from which the request is being done.
     *
     * @return context the context object
     */
    public function get_context(): context {
        return $this->context;
    }

    /**
     * The component name from which the request is being done.
     *
     * @return string the component name, for example 'block_ai_chat' or 'tiny_ai'
     */
    public function get_component(): string {
        return $this->component;
    }

    /**
     * Getter for additional options as associative array.
     *
     * @return array an array containing additional options
     */
    public function get_options(): array {
        return $this->options;
    }

    /**
     * Setter for the options.
     *
     * Needed to update the options inside the object after they have been altered.
     *
     * @param array $options The options array which should overwrite the current one inside the object
     */
    public function set_options(array $options): void {
        $this->options = $options;
    }

    /**
     * Helper function that sanitizes the options against the options defined in the purpose class.
     *
     * @throws coding_exception if validation is failing
     */
    public function sanitize_options(): void {
        foreach ($this->options as $key => $value) {
            if (!array_key_exists($key, $this->purpose->get_available_purpose_options())) {
                throw new coding_exception('Option ' . $key . ' is not allowed for the purpose ' .
                    $this->purpose->get_plugin_name());
            }
            if (is_array($this->purpose->get_available_purpose_options()[$key])) {
                if (
                    !in_array($value[0], array_map(
                        fn($valueobject) => $valueobject['key'],
                        $this->purpose->get_available_purpose_options()[$key]
                    ))
                ) {
                    throw new coding_exception('Value ' . $value[0] . ' for option ' . $key . ' is not allowed for the purpose ' .
                        $this->purpose->get_plugin_name());
                }
            } else {
                if ($this->purpose->get_available_purpose_options()[$key] === base_purpose::PARAM_ARRAY) {
                    array_walk_recursive($value, fn($text) => clean_param($text, PARAM_NOTAGS));
                } else {
                    $this->options[$key] = clean_param($value, $this->purpose->get_available_purpose_options()[$key]);
                }
            }
        }
    }
}

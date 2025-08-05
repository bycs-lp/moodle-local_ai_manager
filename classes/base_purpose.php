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

use core\http_client;
use core_plugin_manager;
use local_ai_manager\local\prompt_response;
use local_ai_manager\local\request_response;
use local_ai_manager\local\unit;
use Psr\Http\Message\StreamInterface;

/**
 * Base class for purpose subplugins.
 *
 * @package    local_ai_manager
 * @copyright  ISB Bayern, 2024
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class base_purpose {

    public const PLACEHOLDER = 'placeholder';
    const PARAM_ARRAY = 'array';

    public function get_request_options(array $options): array {
        return $options;
    }

    public static function get_all_purposes(): array {
        return core_plugin_manager::instance()->get_enabled_plugins('aipurpose');
    }

    public static function get_purpose_tool_config_key(string $purpose): string {
        return 'purpose_' . $purpose . '_tool';
    }

    public final function get_plugin_name(): string {
        return preg_replace('/^aipurpose_(.*)\\\\.*/', '$1', get_class($this));
    }

    public final function get_available_purpose_options(): ?array {
        $options = [];
        $options['component'] = PARAM_TEXT;
        $options['contextid'] = PARAM_INT;
        $options['itemid'] = PARAM_INT;
        $options['forcenewitemid'] = PARAM_BOOL;
        $additionalpurposeoptions = $this->get_additional_purpose_options();
        foreach (array_keys($additionalpurposeoptions) as $purposeoption) {
            if (in_array($purposeoption, $options)) {
                throw new \coding_exception('You must not define options in the purpose subclass which are being used in the '
                . 'base class.');
            }
        }
        return $options + $additionalpurposeoptions;
    }

    public function get_additional_purpose_options(): array {
        return [];
    }

    /**
     * Most AI tools will return Markdown code, so we use this as default.
     *
     * Can be overwritten by purposes which return special content, for example single strings which should not be wrapped
     * or cleaned.
     *
     * @param string $output the output/result from the API of the AI tool
     * @return string the formatted output
     */
    public function format_output(string $output): string {
        return format_text($output, FORMAT_MARKDOWN, ['filter' => false]);
    }

}

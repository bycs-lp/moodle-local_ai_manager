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
 * Purpose chat methods
 *
 * @package    aipurpose_agent
 * @copyright  ISB Bayern, 2024
 * @author     Andreas Wagner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aipurpose_agent;

use local_ai_manager\base_purpose;
use local_ai_manager\request_options;

/**
 * Purpose AI-Agent
 *
 * @package    aipurpose_agent
 * @copyright  ISB Bayern, 2024
 * @author     Andreas Wagner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purpose extends base_purpose {

    /** @var array @var array keep the rawoptions during processing */
    protected $rawoptions = [];

    #[\Override]
    public function get_additional_request_options(array $options): array {
        $this->rawoptions = $options;
        return $options;
    }

    #[\Override]
    public function get_additional_purpose_options(): array {
        return ['agentoptions' => base_purpose::PARAM_ARRAY];
    }

    #[\Override]
    public function format_prompt_text(string $prompttext, request_options $requestoptions): string {
        global $CFG;

        $sanitizedoptions = $requestoptions->get_options();

        // If $sanitizedoptions contains domelements add genericprompt and add domelements.
        if (!isset($sanitizedoptions['agentoptions']['domelements'])) {
            return $prompttext;
        }
        $genericprompt = file_get_contents($CFG->dirroot . '/local/ai_manager/purposes/agent/assets/genericprompt.txt');
        $formatedprompt = $genericprompt . json_encode($sanitizedoptions);

        return $formatedprompt;
    }

    #[\Override]
    public function format_output(string $output): string {

        // Do a basic validation here.
        $output = trim($output);
        $outputrecord = json_decode($output, true);

        $erroroutput = [
                'formelements' => [],
                'chatoutput' => [
                        'type' => 'intro',
                        'text' => 'Sorry, I did not understand your input.'
                ]
        ];

        if (!isset($outputrecord['formelements'])) {
            return $output;
        }

        return $output;
    }
}

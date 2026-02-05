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
 * @package    aipurpose_rag
 * @copyright  ISB Bayern, 2024
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aipurpose_rag;

use local_ai_manager\base_purpose;

/**
 * Purpose chat methods
 *
 * @package    aipurpose_rag
 * @copyright  ISB Bayern, 2024
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purpose extends base_purpose {

    #[\Override]
    public function get_additional_request_options(array $options): array {
        return $options;
    }

   #[\Override]
   public function get_additional_purpose_options(): array {
        $sharedopts =  [
            'action' => PARAM_TEXT,
            'topk' => PARAM_INT,
            'content' => PARAM_RAW,
            'document' => self::PARAM_ARRAY,
            'metadata' => self::PARAM_ARRAY,
        ];
        
        
        return $sharedopts;
   }

   /**
    * Override to constrain the results to the original JSON (generally).
    */
   #[\Override]
   public function format_output(string $output): string
   {
       return $output;
   }
}

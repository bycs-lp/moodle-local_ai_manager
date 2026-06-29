<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.  

namespace aitool_openaite;

use local_ai_manager\local\prompt_response;
use local_ai_manager\local\unit;
use local_ai_manager\local\usage;
use local_ai_manager\request_options;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\StreamInterface;

/**
 * aitool_openaite connector class.
 *
 * @package    aitool_openaite
 * @copyright  2025 University of Strathclyde, Michael Hughes
 * @author     Michael Hughes
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class connector  extends \aitool_chatgpt\connector {
    #[\Override]
    public function get_models_by_purpose(): array {
        return [

                'embedding' =>['text-embedding-3-small', 'text-embedding-3-large'],
        ];
    }
    #[\Override]
    public function get_prompt_data(string $prompttext, request_options $requestoptions): array {
        return [
                'input' => $prompttext,
                'model' => $this->get_instance()->get_model(),
                'encoding_format' => 'float',
        ];
    }
     #[\Override]
    public function execute_prompt_completion(StreamInterface $result, request_options $requestoptions): prompt_response {
        $content = json_decode($result->getContents(), true);
        return prompt_response::create_from_result(
                $content['model'],
                new usage(0, 0, 0),
                implode(",", $content['data'][0]['embedding'])
        );
    }
}
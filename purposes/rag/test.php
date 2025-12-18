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
 * TODO describe file test
 *
 * @package    aipurpose_rag
 * @copyright  2025 University Of Strathclyde <learning-technologies@strath.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use tool_brickfield\local\areas\mod_choice\option;

require('../../../../config.php');

require_login();
$context = context_system::instance();

$url = new moodle_url('/local/ai_manager/purposes/rag/test.php', []);
$PAGE->set_url($url);
$PAGE->set_context($context);

$PAGE->set_heading($SITE->fullname);
echo $OUTPUT->header();
$output = [];
$manager = new \local_ai_manager\manager('rag');
echo \html_writer::start_tag('pre');
$action = optional_param('action', '', PARAM_ALPHA);
if ($action == "store") {
    // Test 1 store a doc.
    
    $storeprompt = json_encode([
        'content' => 'This is a test document. It is only a test document.',
        'metadata' => [
            'title' => 'Test Document',
            'author' => 'Moodle AI Manager',
            'source' => 'Generated',
        ],
    ]);
    $storeoptions = [
        'action' => 'store',
    ];
    $storeresponse = $manager->perform_request(
        $storeprompt,
        'aitool_rag',
        $context->id,
        $storeoptions
    );

    $storeresult = $storeresponse->get_content();

    var_dump($storeresult);
}
if ($action == "fetch") {
    // Test 2 retrive a doc.
    $query = optional_param('query', false, PARAM_RAW);
    if ($query !== false) {
        // we have a user query.
        $retrieveoptions = [
            'action' => 'retrieve',
            'topk' => 1,
        ];
        $retrieveresponse = $manager->perform_request(
            $query,
            'aitool_rag',
            $context->id,
            $retrieveoptions
        );

        if ($retrieveresponse->get_code() != 200) {
            throw new \moodle_exception($retrieveresponse->get_errormessage());
        } else {
            $retrieveresult = json_decode($retrieveresponse->get_content());
            $results = $retrieveresult->result->points;

            form();
            foreach($results as $r) {
                $title = $r->payload->title ?? 'No Title';
                echo "Result: {$title} ({$r->score})\n";
                echo "Content: {$r->payload->content}\n";
                echo "Metadata: \n";
                echo print_r($r->payload->metadata, true)."\n";
                // echo print_r($r->payload, true)."\n";
            }
        }
        
        
    } else { 
        echo "Fetch test";
        form();   
    }

}

function form() {
    global $PAGE;
    echo \core\output\html_writer::start_tag('form', [
        'action' => new \moodle_url($PAGE->url,[
            'action'=>'fetch'
        ]),
        'method' => 'POST',
    ]);
    echo \html_writer::tag('input', '', ['name' => 'query']);
    echo \html_writer::tag('input', '', ['type' => 'submit', 'value' => 'Query']);
    echo \html_writer::end_tag('form');
}
echo \html_writer::end_tag('pre');
// echo \html_writer::alist($output);
echo $OUTPUT->footer();

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
 * TEMPORARY demo page for testing the embedding purpose.
 *
 * This is a throwaway page for demo purposes only and should NOT be shipped to production.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();
$context = \core\context\system::instance();
// Demo page: restrict to site admins only.
require_capability('moodle/site:config', $context);

$phrase = optional_param('phrase', '', PARAM_TEXT);

$PAGE->set_url(new moodle_url('/local/ai_manager/test_embedding.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Embedding demo');
$PAGE->set_heading('Embedding demo');

echo $OUTPUT->header();
echo $OUTPUT->notification(
    'Temporary demo page – tests the "embedding" purpose. Remove before going to production.',
    \core\output\notification::NOTIFY_WARNING
);

// Simple input form.
echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $PAGE->url->out(false),
    'class' => 'mb-4',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::tag('label', 'Phrase to embed', ['for' => 'phrase', 'class' => 'form-label fw-bold']);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'id' => 'phrase',
    'name' => 'phrase',
    'value' => $phrase,
    'class' => 'form-control mb-2',
    'placeholder' => 'e.g. The quick brown fox jumps over the lazy dog',
    'size' => 80,
]);
echo html_writer::tag('button', 'Generate embedding', ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');

// Process the request once a phrase has been submitted.
if ($phrase !== '' && confirm_sesskey()) {
    try {
        $manager = new \local_ai_manager\manager('embedding');
        $response = $manager->perform_request($phrase, 'local_ai_manager', $context->id);

        if ($response->get_code() !== 200) {
            echo $OUTPUT->notification(
                'Request failed (HTTP ' . $response->get_code() . '): ' . s($response->get_errormessage()),
                \core\output\notification::NOTIFY_ERROR
            );
            // Show additional debug information to help diagnose the failure during the demo.
            $debuginfo = $response->get_debuginfo();
            if ($debuginfo !== '') {
                echo $OUTPUT->heading('Debug info', 5);
                echo html_writer::tag('pre', s($debuginfo), ['class' => 'bg-light p-3 border rounded']);
            }
        } else {
            $embedding = json_decode($response->get_content(), true);
            if (!is_array($embedding) || empty($embedding)) {
                echo $OUTPUT->notification(
                    'The response did not contain a usable embedding vector.',
                    \core\output\notification::NOTIFY_ERROR
                );
            } else {
                $dimensions = count($embedding);
                $preview = array_slice($embedding, 0, 10);

                echo $OUTPUT->notification(
                    'Embedding generated successfully.',
                    \core\output\notification::NOTIFY_SUCCESS
                );

                $info = new html_table();
                $info->attributes['class'] = 'generaltable w-auto';
                $info->data = [
                    ['Model', s($response->get_modelinfo())],
                    ['Dimensions', $dimensions],
                    ['Tokens used', format_float($response->get_usage()->value, 0)],
                ];
                echo html_writer::table($info);

                echo $OUTPUT->heading('First ' . count($preview) . ' of ' . $dimensions . ' values', 4);
                echo html_writer::tag(
                    'pre',
                    s('[' . implode(', ', array_map(fn($v) => sprintf('%.6f', $v), $preview)) . ', ...]'),
                    ['class' => 'bg-light p-3 border rounded']
                );
            }
        }
    } catch (\moodle_exception $e) {
        echo $OUTPUT->notification(
            'Could not run embedding request: ' . s($e->getMessage())
                . '<br>Make sure an AI tool instance is assigned to the "embedding" purpose for your role.',
            \core\output\notification::NOTIFY_ERROR
        );
        // Show debug info / stack trace to help diagnose the failure during the demo.
        $debuginfo = !empty($e->debuginfo) ? $e->debuginfo : $e->getTraceAsString();
        echo $OUTPUT->heading('Debug info', 5);
        echo html_writer::tag('pre', s($debuginfo), ['class' => 'bg-light p-3 border rounded']);
    } catch (\Throwable $e) {
        echo $OUTPUT->notification(
            'Unexpected error: ' . s($e->getMessage()),
            \core\output\notification::NOTIFY_ERROR
        );
        echo $OUTPUT->heading('Debug info', 5);
        echo html_writer::tag('pre', s($e->getTraceAsString()), ['class' => 'bg-light p-3 border rounded']);
    }
}

echo $OUTPUT->footer();

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
 * Data generator for local_ai_manager.
 *
 * @package   local_ai_manager
 * @copyright 2025 ISB Bayern
 * @author    Philipp Memmel
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_ai_manager_generator extends component_generator_base {
    /**
     * Create a (fake) request log entry.
     *
     * @param array $record the record data if you want to override any defaults.
     * @return stdClass the created log entry.
     */
    public function create_request_log_entry(array $record = []): stdClass {
        global $DB, $USER;
        $block = $this->datagenerator->create_block(
        // We, of course, usually are using block_ai_chat, not block_html, but we do not
        // want to introduce an unnecessary dependency here, and it does not matter for the log entry.
            'html',
            ['parentcontextid' => SYSCONTEXTID]
        );
        $itemid = isset($record['itemid']) ? $record['itemid'] : 10;
        $contextid = \context_block::instance($block->id)->id;
        $default = [
            'userid' => $USER->id,
            'tenant' => '',
            'value' => 132.000,
            'customvalue1' => '80.000',
            'customvalue2' => '52.000',
            'purpose' => 'chat',
            'connector' => 'chatgpt',
            'model' => 'gpt-4o',
            'modelinfo' => 'gpt-4o-2024-08-06',
            'duration' => '3.243',
            'prompttext' => 'Some example prompt',
            'promptcompletion' => 'Some example completion',
            'requestoptions' => '{"itemid":' . $itemid .
                ',"conversationcontext":[{"message":"Hi, I\'m Fred.","sender":"user"},'
                . '{"message":"<p>Hi Fred, what can I do for you?</p>","sender":"ai"}]}',
            'component' => 'block_ai_chat',
            'contextid' => $contextid,
            'coursecontextid' => 1,
            'itemid' => 10,
            'deleted' => 0,
            'timecreated' => time(),
        ];

        $data = (object) array_merge($default, $record);
        $data->id = $DB->insert_record('local_ai_manager_request_log', $data);

        return $data;
    }
}

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

namespace aipurpose_questiongeneration;

/**
 * Unit tests for the qipurpose_questiongeneration purpose class.
 *
 * @package   aipurpose_questiongeneration
 * @copyright 2025 ISB Bayern
 * @author    Philipp Memmel
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class purpose_test extends \advanced_testcase {
    /**
     * Tests the output formatting of question generation results.
     *
     * @covers \aipurpose_questiongeneration\purpose::format_output
     */
    public function test_format_output(): void {
        global $CFG;
        $factory = \core\di::get(\local_ai_manager\local\connector_factory::class);
        $questiongenerationpurpose = $factory->get_purpose_by_purpose_string('questiongeneration');
        $plainxml =
            file_get_contents($CFG->dirroot . '/local/ai_manager/purposes/questiongeneration/tests/fixtures/multichoice.xml');

        $returnstringwithspaces = '      ' . $plainxml . '     ';
        $this->assertEquals($plainxml, $questiongenerationpurpose->format_output($returnstringwithspaces));

        $returnstringwithspacesandnewlines = "\n\n\n" . '      ' . $plainxml . '     ' . "\n\n";
        $this->assertEquals($plainxml, $questiongenerationpurpose->format_output($returnstringwithspacesandnewlines));

        $returnstringwithmarkdowncodeformatting = "\u{0060}\u{0060}\u{0060}" . $plainxml . "\u{0060}\u{0060}\u{0060}";
        $this->assertEquals($plainxml, $questiongenerationpurpose->format_output($returnstringwithmarkdowncodeformatting));

        $returnstringwithmarkdowncodeformattingandtext = 'Here is the question you asked me for:' . "\n\n"
            . "\u{0060}\u{0060}\u{0060}" . $plainxml . "\u{0060}\u{0060}\u{0060}\n\n"
            . 'This is the question I generated. Let me know if I can do anything else for you.';
        $this->assertEquals($plainxml, $questiongenerationpurpose->format_output($returnstringwithmarkdowncodeformattingandtext));

        // Check if UTF-8 codes are properly converted into UTF-8 symbols.
        $returnstringwithutf8codes =
            'Fr\u00f6hliche K\u00f6che l\u00f6sen w\u00e4hrend der Mittagsp\u00e4use r\u00e4tselhafte Pr\u00fcfungsb\u00f6gen';
        $this->assertEquals(
            'Fröhliche Köche lösen während der Mittagspäuse rätselhafte Prüfungsbögen',
            $questiongenerationpurpose->format_output($returnstringwithutf8codes)
        );

        // In case of not explicitly marking the code with markdown code blocks there is no way to determine what part of the text
        // the real question is (we cannot assume it is XML, it can be GIFT or something else).
        // So we have to leave the result as it is. Question parsing will probably fail in this case if the frontend plugin
        // does not do some parsing on its own.
        $returnstringwithoutmarkdowncodeformattingandtext = $plainxml . "\n\n"
            . 'This is the question I generated. Let me know if I can do anything else for you.';
        $this->assertEquals(
            $returnstringwithoutmarkdowncodeformattingandtext,
            $questiongenerationpurpose->format_output($returnstringwithoutmarkdowncodeformattingandtext)
        );
    }
}

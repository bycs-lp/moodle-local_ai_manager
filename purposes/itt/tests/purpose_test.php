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

namespace aipurpose_itt;

use core_plugin_manager;
use local_ai_manager\base_purpose;
use local_ai_manager\local\config_manager;
use local_ai_manager\local\connector_factory;
use local_ai_manager\local\userinfo;

/**
 * Tests for itt purpose.
 *
 * @package   aipurpose_itt
 * @copyright 2025 ISB Bayern
 * @author    Philipp Memmel
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class purpose_test extends \advanced_testcase {
    /**
     * Data provider for test_format_output.
     *
     * @return array test cases
     */
    public static function format_output_provider(): array {
        return [
            'plain text remains unchanged' => [
                'input' => 'This is plain text output from OCR.',
                'expected' => 'This is plain text output from OCR.',
            ],
            'asterisks used as multiplication are preserved' => [
                'input' => '=B$3*B6',
                'expected' => '=B$3*B6',
            ],
            'spreadsheet formulas with multiple asterisks are preserved' => [
                'input' => "Montag 20 =B\$3*B6\nDienstag 210 =B\$3*B7\nMittwoch 80 =B\$3*B8",
                'expected' => "Montag 20 =B\$3*B6\nDienstag 210 =B\$3*B7\nMittwoch 80 =B\$3*B8",
            ],
            'markdown bold syntax is not converted to html' => [
                'input' => 'This is **bold** text',
                'expected' => 'This is **bold** text',
            ],
            'markdown italic syntax is not converted to html' => [
                'input' => 'This is *italic* text',
                'expected' => 'This is *italic* text',
            ],
            'markdown headings are not converted to html' => [
                'input' => "## Heading\nSome content",
                'expected' => "## Heading\nSome content",
            ],
            'markdown list is not converted to html' => [
                'input' => "- Item 1\n- Item 2\n- Item 3",
                'expected' => "- Item 1\n- Item 2\n- Item 3",
            ],
            'empty string returns empty string' => [
                'input' => '',
                'expected' => '',
            ],
            'complex spreadsheet extraction is preserved' => [
                'input' => "Tabelle1\n\nA B C D\n1 Fahrkosten einer Woche\n"
                    . "6 Montag 20 =B\$3*B6\n7 Dienstag 210 =B\$3*B7\n"
                    . "13 Gesamt =SUMME(B6:B12) =SUMME(C6:C12)\n"
                    . "15 Wochendurchschnitt =MITTELWERT(B6:B12) =MITTELWERT(C6:C12)",
                'expected' => "Tabelle1\n\nA B C D\n1 Fahrkosten einer Woche\n"
                    . "6 Montag 20 =B\$3*B6\n7 Dienstag 210 =B\$3*B7\n"
                    . "13 Gesamt =SUMME(B6:B12) =SUMME(C6:C12)\n"
                    . "15 Wochendurchschnitt =MITTELWERT(B6:B12) =MITTELWERT(C6:C12)",
            ],
            'dollar signs in cell references are preserved' => [
                'input' => '=B$3*B6 =$A$1+$B$2',
                'expected' => '=B$3*B6 =$A$1+$B$2',
            ],
        ];
    }

    /**
     * Tests that format_output returns plain text without Markdown-to-HTML conversion.
     *
     * @covers \aipurpose_itt\purpose::format_output
     * @dataProvider format_output_provider
     * @param string $input the raw LLM output
     * @param string $expected the expected output after format_output
     */
    public function test_format_output(string $input, string $expected): void {
        $purpose = new purpose();
        $this->assertEquals($expected, $purpose->format_output($input));
    }

    /**
     * Tests that format_output does NOT produce em tags from asterisks.
     *
     * This is the specific regression that caused MBS-10770: spreadsheet formulas
     * like =B$3*B6 were being converted to =B$3<em>B6 by the base class.
     *
     * @covers \aipurpose_itt\purpose::format_output
     */
    public function test_format_output_does_not_produce_em_tags(): void {
        $purpose = new purpose();
        $input = "6 Montag 20 =B\$3*B6\n7 Dienstag 210 =B\$3*B7\n8 Mittwoch 80 =B\$3*B8";
        $result = $purpose->format_output($input);
        $this->assertStringNotContainsString('<em>', $result);
        $this->assertStringNotContainsString('</em>', $result);
        $this->assertStringContainsString('*', $result);
    }

    /**
     * Tests that format_output strips dangerous HTML tags for XSS prevention.
     *
     * @covers \aipurpose_itt\purpose::format_output
     */
    public function test_format_output_strips_dangerous_html(): void {
        $this->resetAfterTest();
        $purpose = new purpose();
        $input = '<script>alert("xss")</script>Normal text';
        $result = $purpose->format_output($input);
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('Normal text', $result);
    }

    /**
     * Makes sure that all connector plugins that declare themselves compatible with the itt purpose also define allowed mimetypes.
     *
     * @covers \aipurpose_itt\purpose::get_allowed_mimetypes
     * @covers \local_ai_manager\base_connector::allowed_mimetypes
     */
    public function test_get_allowed_mimetypes(): void {
        $this->resetAfterTest();
        $connectorfactory = \core\di::get(connector_factory::class);
        foreach (array_keys(core_plugin_manager::instance()->get_installed_plugins('aitool')) as $aitool) {
            $newconnector = $connectorfactory->get_connector_by_connectorname($aitool);
            if (!empty($newconnector->get_models_by_purpose()['itt'])) {
                // Some connectors rely on a really existing instance, so we create one.
                $newinstance = $connectorfactory->get_new_instance($aitool);
                $newinstance->set_name('Test instance');
                $newinstance->set_endpoint('https://example.com');
                $newinstance->store();

                $empty = true;
                // We check that the connector returns at least for one of the models a non-empty list of allowed mimetypes.
                foreach ($newconnector->get_models_by_purpose()['itt'] as $model) {
                    $newinstance->set_model($model);
                    $newinstance->store();
                    $configmanager = \core\di::get(config_manager::class);
                    $configmanager->set_config(
                        base_purpose::get_purpose_tool_config_key('itt', userinfo::ROLE_BASIC),
                        $newinstance->get_id()
                    );
                    $connector = $connectorfactory->get_connector_by_purpose('itt', userinfo::ROLE_BASIC);
                    if (!empty($connector->allowed_mimetypes())) {
                        $empty = false;
                        break;
                    }
                }
                $this->assertFalse($empty);
            }
        }
    }
}

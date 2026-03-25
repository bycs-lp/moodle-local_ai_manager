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

use core_plugin_manager;
use local_ai_manager\local\connector_factory;

/**
 * Test class for the base_purpose class.
 *
 * @package    local_ai_manager
 * @copyright  2025 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class base_purpose_test extends \advanced_testcase {
    /** @var string Triple backtick for markdown code blocks. */
    private const CODEBLOCK = "\x60\x60\x60";

    /** @var string Single backtick for inline code. */
    private const BACKTICK = "\x60";

    /**
     * Test if all purpose plugins have a proper description.
     *
     * A purpose plugin either has to define the lang string 'purposedescription' in its lang file or customize its description
     * by overwriting base_purpose::get_description.
     *
     * @param string $purpose The purpose to check as string
     * @covers       \local_ai_manager\base_purpose::get_description
     * @dataProvider get_description_provider
     */
    public function test_get_description(string $purpose): void {
        $connectorfactory = \core\di::get(connector_factory::class);
        $purposeinstance = $connectorfactory->get_purpose_by_purpose_string($purpose);
        $reflector = new \ReflectionMethod($purposeinstance, 'get_description');
        $ismethodoverwritten = $reflector->getDeclaringClass()->getName() === get_class($purposeinstance);
        if (!$ismethodoverwritten) {
            $stringmanager = get_string_manager();
            $this->assertTrue($stringmanager->string_exists('purposedescription', 'aipurpose_' . $purpose));
            $this->assertEquals(
                get_string('purposedescription', 'aipurpose_' . $purpose),
                $purposeinstance->get_description()
            );
        } else {
            $this->assertNotEmpty($purposeinstance->get_description());
        }
    }

    /**
     * Data provider providing an array of all installed purposes.
     *
     * @return array array of names (strings) of installed purpose plugins
     */
    public static function get_description_provider(): array {
        $testcases = [];
        foreach (array_keys(core_plugin_manager::instance()->get_installed_plugins('aipurpose')) as $purposestring) {
            $testcases['test_get_description_of_purpose_' . $purposestring] = ['purpose' => $purposestring];
        }
        return $testcases;
    }

    /**
     * Test that HTML in fenced code blocks is escaped and displayed as text.
     *
     * @covers \local_ai_manager\base_purpose::format_output
     */
    public function test_format_output_html_in_fenced_code_block_is_escaped(): void {
        $this->resetAfterTest();
        $purpose = new base_purpose();

        $input = 'Here is HTML:

' . self::CODEBLOCK . 'html
<div class="test"><p>Hello</p></div>
' . self::CODEBLOCK;
        $output = $purpose->format_output($input);

        $this->assertStringContainsString('&lt;div', $output);
        $this->assertStringContainsString('&lt;p&gt;', $output);
        $this->assertStringNotContainsString('<div class="test">', $output);
    }

    /**
     * Test that JavaScript in code blocks cannot execute (XSS prevention).
     *
     * @covers \local_ai_manager\base_purpose::format_output
     */
    public function test_format_output_javascript_in_code_block_is_escaped(): void {
        $this->resetAfterTest();
        $purpose = new base_purpose();

        $input = 'Example:

' . self::CODEBLOCK . 'javascript
alert(\'XSS\');
document.cookie;
' . self::CODEBLOCK;
        $output = $purpose->format_output($input);

        $this->assertStringContainsString('alert(', $output);
        $this->assertStringNotContainsString('<script>', $output);
    }

    /**
     * Test that script tags inside code blocks are escaped.
     *
     * @covers \local_ai_manager\base_purpose::format_output
     */
    public function test_format_output_script_tags_in_code_block_are_escaped(): void {
        $this->resetAfterTest();
        $purpose = new base_purpose();

        $input = 'Code:

' . self::CODEBLOCK . 'html
<script>alert(\'evil\')</script>
' . self::CODEBLOCK;
        $output = $purpose->format_output($input);

        $this->assertStringContainsString('&lt;script&gt;', $output);
        $this->assertStringNotContainsString('<script>alert', $output);
    }

    /**
     * Test that inline code with HTML is escaped.
     *
     * @covers \local_ai_manager\base_purpose::format_output
     */
    public function test_format_output_inline_code_html_is_escaped(): void {
        $this->resetAfterTest();
        $purpose = new base_purpose();

        $input = 'Use the ' . self::BACKTICK . '<div>' . self::BACKTICK . ' element for containers.';
        $output = $purpose->format_output($input);

        $this->assertStringContainsString('&lt;div&gt;', $output);
        $this->assertStringContainsString('<code>', $output);
    }

    /**
     * Test that inline code with script is escaped.
     *
     * @covers \local_ai_manager\base_purpose::format_output
     */
    public function test_format_output_inline_code_script_is_escaped(): void {
        $this->resetAfterTest();
        $purpose = new base_purpose();

        $input = 'Never use ' . self::BACKTICK . '<script>alert(\'xss\')</script>' . self::BACKTICK . ' inline.';
        $output = $purpose->format_output($input);

        $this->assertStringContainsString('&lt;script&gt;', $output);
        $this->assertStringNotContainsString('<script>alert', $output);
    }

    /**
     * Test that event handlers in code are escaped (onerror, onclick, etc.).
     *
     * @covers \local_ai_manager\base_purpose::format_output
     */
    public function test_format_output_event_handlers_in_code_are_escaped(): void {
        $this->resetAfterTest();
        $purpose = new base_purpose();

        $input = 'Example:

' . self::CODEBLOCK . 'html
<img src="x" onerror="alert(\'xss\')">
<button onclick="evil()">Click</button>
' . self::CODEBLOCK;
        $output = $purpose->format_output($input);

        // Event handlers should be visible as escaped text in code block, not as executable attributes.
        $this->assertStringContainsString('onerror=', $output);
        $this->assertStringContainsString('onclick=', $output);
        // The key point: the img tag itself should be escaped, not rendered as HTML.
        $this->assertStringContainsString('&lt;img', $output);
        $this->assertStringContainsString('&lt;button', $output);
    }

    /**
     * Test that raw script tags outside code blocks are sanitized.
     *
     * @covers \local_ai_manager\base_purpose::format_output
     */
    public function test_format_output_raw_script_outside_code_block_is_sanitized(): void {
        $this->resetAfterTest();
        $purpose = new base_purpose();

        $input = "Hello <script>alert('xss')</script> world";
        $output = $purpose->format_output($input);

        $this->assertStringNotContainsString('<script>', $output);
        $this->assertStringNotContainsString('alert(', $output);
        $this->assertStringContainsString('Hello', $output);
        $this->assertStringContainsString('world', $output);
    }

    /**
     * Test SVG with script payload is sanitized.
     *
     * @covers \local_ai_manager\base_purpose::format_output
     */
    public function test_format_output_svg_script_payload_is_sanitized(): void {
        $this->resetAfterTest();
        $purpose = new base_purpose();

        $input = "Image: <svg onload=\"alert('xss')\"><circle r=\"50\"/></svg>";
        $output = $purpose->format_output($input);

        $this->assertStringNotContainsString('onload=', $output);
    }

    /**
     * Test that bold text formatting works.
     *
     * @covers \local_ai_manager\base_purpose::format_output
     */
    public function test_format_output_bold_text_formatting(): void {
        $this->resetAfterTest();
        $purpose = new base_purpose();

        $input = "This is **bold** text.";
        $output = $purpose->format_output($input);

        $this->assertStringContainsString('<strong>bold</strong>', $output);
    }

    /**
     * Test that italic text formatting works.
     *
     * @covers \local_ai_manager\base_purpose::format_output
     */
    public function test_format_output_italic_text_formatting(): void {
        $this->resetAfterTest();
        $purpose = new base_purpose();

        $input = "This is *italic* text.";
        $output = $purpose->format_output($input);

        $this->assertStringContainsString('<em>italic</em>', $output);
    }

    /**
     * Test that unordered lists work.
     *
     * @covers \local_ai_manager\base_purpose::format_output
     */
    public function test_format_output_unordered_list_formatting(): void {
        $this->resetAfterTest();
        $purpose = new base_purpose();

        $input = "List:\n\n- Item 1\n- Item 2\n- Item 3";
        $output = $purpose->format_output($input);

        $this->assertStringContainsString('<ul>', $output);
        $this->assertStringContainsString('<li>', $output);
    }

    /**
     * Test that ordered lists work.
     *
     * @covers \local_ai_manager\base_purpose::format_output
     */
    public function test_format_output_ordered_list_formatting(): void {
        $this->resetAfterTest();
        $purpose = new base_purpose();

        $input = "Steps:\n\n1. First\n2. Second\n3. Third";
        $output = $purpose->format_output($input);

        $this->assertStringContainsString('<ol>', $output);
        $this->assertStringContainsString('<li>', $output);
    }

    /**
     * Test that headings work.
     *
     * @covers \local_ai_manager\base_purpose::format_output
     */
    public function test_format_output_heading_formatting(): void {
        $this->resetAfterTest();
        $purpose = new base_purpose();

        $input = "# Heading 1\n\n## Heading 2\n\n### Heading 3";
        $output = $purpose->format_output($input);

        $this->assertStringContainsString('<h1>', $output);
        $this->assertStringContainsString('<h2>', $output);
        $this->assertStringContainsString('<h3>', $output);
    }

    /**
     * Test that links work (but are safe).
     *
     * @covers \local_ai_manager\base_purpose::format_output
     */
    public function test_format_output_link_formatting(): void {
        $this->resetAfterTest();
        $purpose = new base_purpose();

        $input = "Visit [Moodle](https://moodle.org) for more info.";
        $output = $purpose->format_output($input);

        $this->assertStringContainsString('href="https://moodle.org"', $output);
        $this->assertStringContainsString('>Moodle</a>', $output);
    }

    /**
     * Test that blockquotes work.
     *
     * @covers \local_ai_manager\base_purpose::format_output
     */
    public function test_format_output_blockquote_formatting(): void {
        $this->resetAfterTest();
        $purpose = new base_purpose();

        $input = "> This is a quote.";
        $output = $purpose->format_output($input);

        $this->assertStringContainsString('<blockquote>', $output);
    }

    /**
     * Test that code blocks are wrapped in pre and code tags.
     *
     * @covers \local_ai_manager\base_purpose::format_output
     */
    public function test_format_output_code_block_structure(): void {
        $this->resetAfterTest();
        $purpose = new base_purpose();

        $input = self::CODEBLOCK . 'php
echo \'Hello\';
' . self::CODEBLOCK;
        $output = $purpose->format_output($input);

        $this->assertStringContainsString('<pre>', $output);
        $this->assertStringContainsString('<code', $output);
    }

    /**
     * Test that MathJax inline delimiters are properly escaped.
     *
     * @covers \local_ai_manager\base_purpose::format_output
     */
    public function test_format_output_mathjax_inline_delimiters_escaped(): void {
        $this->resetAfterTest();
        $purpose = new base_purpose();

        $input = 'The formula is \\(x^2 + y^2 = z^2\\)';
        $output = $purpose->format_output($input);

        // MathJax delimiters should be present (escaped for frontend processing).
        $this->assertStringContainsString('\\(', $output);
        $this->assertStringContainsString('\\)', $output);
    }

    /**
     * Test that MathJax display delimiters are properly escaped.
     *
     * @covers \local_ai_manager\base_purpose::format_output
     */
    public function test_format_output_mathjax_display_delimiters_escaped(): void {
        $this->resetAfterTest();
        $purpose = new base_purpose();

        $input = 'Display math: \\[E = mc^2\\]';
        $output = $purpose->format_output($input);

        // MathJax delimiters should be present (escaped for frontend processing).
        $this->assertStringContainsString('\\[', $output);
        $this->assertStringContainsString('\\]', $output);
    }

    /**
     * Test empty input.
     *
     * @covers \local_ai_manager\base_purpose::format_output
     */
    public function test_format_output_empty_input(): void {
        $this->resetAfterTest();
        $purpose = new base_purpose();

        $output = $purpose->format_output('');
        $this->assertEmpty(trim($output));
    }

    /**
     * Test plain text without any markdown.
     *
     * @covers \local_ai_manager\base_purpose::format_output
     */
    public function test_format_output_plain_text(): void {
        $this->resetAfterTest();
        $purpose = new base_purpose();

        $input = 'Just plain text without any formatting.';
        $output = $purpose->format_output($input);

        $this->assertStringContainsString('Just plain text', $output);
    }

    /**
     * Test multiple code blocks in one response.
     *
     * @covers \local_ai_manager\base_purpose::format_output
     */
    public function test_format_output_multiple_code_blocks(): void {
        $this->resetAfterTest();
        $purpose = new base_purpose();

        $input = 'HTML:

' . self::CODEBLOCK . 'html
<div>Test</div>
' . self::CODEBLOCK . '

JS:

' . self::CODEBLOCK . 'javascript
alert(\'hi\');
' . self::CODEBLOCK;
        $output = $purpose->format_output($input);

        $this->assertStringContainsString('&lt;div&gt;', $output);
        $this->assertStringContainsString('alert(', $output);
        $this->assertStringNotContainsString('<div>Test</div>', $output);
    }

    /**
     * Test mixed content: text, code, lists together.
     *
     * @covers \local_ai_manager\base_purpose::format_output
     */
    public function test_format_output_mixed_content(): void {
        $this->resetAfterTest();
        $purpose = new base_purpose();

        $input = '# Tutorial

Here\'s how:

1. Write HTML
2. Add CSS

' . self::CODEBLOCK . 'html
<p>Hello</p>
' . self::CODEBLOCK . '

That\'s **all**!';
        $output = $purpose->format_output($input);

        $this->assertStringContainsString('<h1>', $output);
        $this->assertStringContainsString('<ol>', $output);
        $this->assertStringContainsString('&lt;p&gt;Hello&lt;/p&gt;', $output);
        $this->assertStringContainsString('<strong>all</strong>', $output);
    }

    /**
     * Test that special characters are handled correctly.
     *
     * @covers \local_ai_manager\base_purpose::format_output
     */
    public function test_format_output_special_characters_in_code(): void {
        $this->resetAfterTest();
        $purpose = new base_purpose();

        $input = 'Example:

' . self::CODEBLOCK . '
<>&"\'
' . self::CODEBLOCK;
        $output = $purpose->format_output($input);

        $this->assertStringContainsString('&lt;&gt;&amp;', $output);
    }

    /**
     * Test Windows-style line endings in code blocks.
     *
     * @covers \local_ai_manager\base_purpose::format_output
     */
    public function test_format_output_windows_line_endings(): void {
        $this->resetAfterTest();
        $purpose = new base_purpose();

        $input = "Code:\r\n\r\n" . self::CODEBLOCK . "html\r\n<div>Test</div>\r\n" . self::CODEBLOCK;
        $output = $purpose->format_output($input);

        $this->assertStringContainsString('&lt;div&gt;', $output);
    }
}

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
     * Data provider for HTML escaping tests in code blocks.
     *
     * @return array test cases for HTML escaping
     */
    public static function format_output_html_escaping_provider(): array {
        $codeblock = "\x60\x60\x60";
        $backtick = "\x60";

        return [
            'html_in_fenced_code_block' => [
                'input' => 'Here is HTML:' . PHP_EOL . PHP_EOL
                    . $codeblock . 'html' . PHP_EOL
                    . '<div class="test"><p>Hello</p></div>' . PHP_EOL
                    . $codeblock,
                'mustcontain' => ['&lt;div', '&lt;p&gt;', '<pre>', '<code'],
                'mustnotcontain' => ['<div class="test">'],
            ],
            'javascript_in_code_block' => [
                'input' => 'Example:' . PHP_EOL . PHP_EOL
                    . $codeblock . 'javascript' . PHP_EOL
                    . '<script>alert(\'XSS\');</script>' . PHP_EOL
                    . 'document.cookie;' . PHP_EOL
                    . $codeblock,
                'mustcontain' => ['alert(', '&lt;script&gt;'],
                'mustnotcontain' => ['<script>alert'],
            ],
            'script_tags_in_code_block' => [
                'input' => 'Code:' . PHP_EOL . PHP_EOL
                    . $codeblock . 'html' . PHP_EOL
                    . '<script>alert(\'evil\')</script>' . PHP_EOL
                    . $codeblock,
                'mustcontain' => ['&lt;script&gt;', '<pre>', '<code'],
                'mustnotcontain' => ['<script>alert'],
            ],
            'inline_code_html' => [
                'input' => 'Use the ' . $backtick . '<div>' . $backtick . ' element for containers.',
                'mustcontain' => ['&lt;div&gt;', '<code>'],
                'mustnotcontain' => [],
            ],
            'inline_code_script' => [
                'input' => 'Never use ' . $backtick . '<script>alert(\'xss\')</script>' . $backtick . ' inline.',
                'mustcontain' => ['&lt;script&gt;'],
                'mustnotcontain' => ['<script>alert'],
            ],
            'event_handlers_in_code' => [
                'input' => 'Example:' . PHP_EOL . PHP_EOL
                    . $codeblock . 'html' . PHP_EOL
                    . '<img src="x" onerror="alert(\'xss\')">' . PHP_EOL
                    . '<button onclick="evil()">Click</button>' . PHP_EOL
                    . $codeblock,
                'mustcontain' => ['onerror=', 'onclick=', '&lt;img', '&lt;button'],
                'mustnotcontain' => [],
            ],
            'multiple_code_blocks' => [
                'input' => 'HTML:' . PHP_EOL . PHP_EOL
                    . $codeblock . 'html' . PHP_EOL
                    . '<div>Test</div>' . PHP_EOL
                    . $codeblock . PHP_EOL . PHP_EOL
                    . 'JS:' . PHP_EOL . PHP_EOL
                    . $codeblock . 'javascript' . PHP_EOL
                    . 'alert(\'hi\');' . PHP_EOL
                    . $codeblock,
                'mustcontain' => ['&lt;div&gt;', 'alert('],
                'mustnotcontain' => ['<div>Test</div>'],
            ],
            'special_characters_in_code' => [
                'input' => 'Example:' . PHP_EOL . PHP_EOL
                    . $codeblock . PHP_EOL
                    . '<>&"\'' . PHP_EOL
                    . $codeblock,
                'mustcontain' => ['&lt;&gt;&amp;'],
                'mustnotcontain' => [],
            ],
        ];
    }

    /**
     * Test that HTML in code blocks is escaped and displayed as text.
     *
     * @param string $input The markdown input
     * @param array $mustcontain Strings that must be in the output
     * @param array $mustnotcontain Strings that must not be in the output
     * @covers \local_ai_manager\base_purpose::format_output
     * @dataProvider format_output_html_escaping_provider
     */
    public function test_format_output_html_escaping(string $input, array $mustcontain, array $mustnotcontain): void {
        $purpose = new base_purpose();
        $output = $purpose->format_output($input);

        foreach ($mustcontain as $expected) {
            $this->assertStringContainsString($expected, $output);
        }
        foreach ($mustnotcontain as $notexpected) {
            $this->assertStringNotContainsString($notexpected, $output);
        }
    }

    /**
     * Data provider for XSS sanitization tests outside code blocks.
     *
     * @return array test cases for XSS sanitization
     */
    public static function format_output_xss_sanitization_provider(): array {
        return [
            'raw_script_outside_code_block' => [
                'input' => 'Hello <script>alert(\'xss\')</script> world',
                'mustcontain' => ['Hello', 'world'],
                'mustnotcontain' => ['<script>', 'alert('],
            ],
            'svg_script_payload' => [
                'input' => 'Image: <svg onload="alert(\'xss\')"><circle r="50"/></svg>',
                'mustcontain' => [],
                'mustnotcontain' => ['onload='],
            ],
        ];
    }

    /**
     * Test that XSS payloads outside code blocks are sanitized.
     *
     * @param string $input The input with potential XSS
     * @param array $mustcontain Strings that must be in the output
     * @param array $mustnotcontain Strings that must not be in the output
     * @covers \local_ai_manager\base_purpose::format_output
     * @dataProvider format_output_xss_sanitization_provider
     */
    public function test_format_output_xss_sanitization(string $input, array $mustcontain, array $mustnotcontain): void {
        $purpose = new base_purpose();
        $output = $purpose->format_output($input);

        foreach ($mustcontain as $expected) {
            $this->assertStringContainsString($expected, $output);
        }
        foreach ($mustnotcontain as $notexpected) {
            $this->assertStringNotContainsString($notexpected, $output);
        }
    }

    /**
     * Data provider for markdown formatting tests.
     *
     * @return array test cases for markdown formatting
     */
    public static function format_output_markdown_formatting_provider(): array {
        $codeblock = "\x60\x60\x60";

        return [
            'bold_text' => [
                'input' => 'This is **bold** text.',
                'mustcontain' => ['<strong>bold</strong>'],
            ],
            'italic_text' => [
                'input' => 'This is *italic* text.',
                'mustcontain' => ['<em>italic</em>'],
            ],
            'unordered_list' => [
                'input' => 'List:' . PHP_EOL . PHP_EOL . '- Item 1' . PHP_EOL . '- Item 2' . PHP_EOL . '- Item 3',
                'mustcontain' => ['<ul>', '<li>'],
            ],
            'ordered_list' => [
                'input' => 'Steps:' . PHP_EOL . PHP_EOL . '1. First' . PHP_EOL . '2. Second' . PHP_EOL . '3. Third',
                'mustcontain' => ['<ol>', '<li>'],
            ],
            'headings' => [
                'input' => '# Heading 1' . PHP_EOL . PHP_EOL . '## Heading 2' . PHP_EOL . PHP_EOL . '### Heading 3',
                'mustcontain' => ['<h1>', '<h2>', '<h3>'],
            ],
            'link' => [
                'input' => 'Visit [Moodle](https://moodle.org) for more info.',
                'mustcontain' => ['href="https://moodle.org"', '>Moodle</a>'],
            ],
            'blockquote' => [
                'input' => '> This is a quote.',
                'mustcontain' => ['<blockquote>'],
            ],
            'code_block_structure' => [
                'input' => $codeblock . 'php' . PHP_EOL . 'echo \'Hello\';' . PHP_EOL . $codeblock,
                'mustcontain' => ['<pre>', '<code'],
            ],
        ];
    }

    /**
     * Test that markdown formatting produces expected HTML.
     *
     * @param string $input The markdown input
     * @param array $mustcontain Strings that must be in the output
     * @covers \local_ai_manager\base_purpose::format_output
     * @dataProvider format_output_markdown_formatting_provider
     */
    public function test_format_output_markdown_formatting(string $input, array $mustcontain): void {
        $purpose = new base_purpose();
        $output = $purpose->format_output($input);

        foreach ($mustcontain as $expected) {
            $this->assertStringContainsString($expected, $output);
        }
    }

    /**
     * Test that MathJax inline delimiters are properly escaped.
     *
     * @covers \local_ai_manager\base_purpose::format_output
     */
    public function test_format_output_mathjax_inline_delimiters_escaped(): void {
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
        $purpose = new base_purpose();
        $input = 'Just plain text without any formatting.';
        $output = $purpose->format_output($input);

        $this->assertStringContainsString('Just plain text', $output);
    }

    /**
     * Test mixed content: text, code, lists together.
     *
     * @covers \local_ai_manager\base_purpose::format_output
     */
    public function test_format_output_mixed_content(): void {
        $purpose = new base_purpose();
        $input = '# Tutorial' . PHP_EOL . PHP_EOL
            . 'Here\'s how:' . PHP_EOL . PHP_EOL
            . '1. Write HTML' . PHP_EOL
            . '2. Add CSS' . PHP_EOL . PHP_EOL
            . self::CODEBLOCK . 'html' . PHP_EOL
            . '<p>Hello</p>' . PHP_EOL
            . self::CODEBLOCK . PHP_EOL . PHP_EOL
            . 'That\'s **all**!';
        $output = $purpose->format_output($input);

        $this->assertStringContainsString('<h1>', $output);
        $this->assertStringContainsString('<ol>', $output);
        $this->assertStringContainsString('&lt;p&gt;Hello&lt;/p&gt;', $output);
        $this->assertStringContainsString('<strong>all</strong>', $output);
    }

    /**
     * Test Windows-style line endings in code blocks.
     *
     * @covers \local_ai_manager\base_purpose::format_output
     */
    public function test_format_output_windows_line_endings(): void {
        $purpose = new base_purpose();
        $input = "Code:\r\n\r\n" . self::CODEBLOCK . "html\r\n<div>Test</div>\r\n" . self::CODEBLOCK;
        $output = $purpose->format_output($input);

        $this->assertStringContainsString('&lt;div&gt;', $output);
    }
}

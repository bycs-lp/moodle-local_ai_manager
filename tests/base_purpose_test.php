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
                'input' => 'Here is HTML:' . "\n\n"
                    . $codeblock . 'html' . "\n"
                    . '<div class="test"><p>Hello</p></div>' . "\n"
                    . $codeblock,
                'mustcontain' => ['&lt;div', '&lt;p&gt;', '<pre>', '<code'],
                'mustnotcontain' => ['<div class="test">'],
            ],
            'javascript_in_code_block' => [
                'input' => 'Example:' . "\n\n"
                    . $codeblock . 'javascript' . "\n"
                    . '<script>alert(\'XSS\');</script>' . "\n"
                    . 'document.cookie;' . "\n"
                    . $codeblock,
                'mustcontain' => ['alert(', '&lt;script&gt;'],
                'mustnotcontain' => ['<script>alert'],
            ],
            'script_tags_in_code_block' => [
                'input' => 'Code:' . "\n\n"
                    . $codeblock . 'html' . "\n"
                    . '<script>alert(\'evil\')</script>' . "\n"
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
                'input' => 'Example:' . "\n\n"
                    . $codeblock . 'html' . "\n"
                    . '<img src="x" onerror="alert(\'xss\')">' . "\n"
                    . '<button onclick="evil()">Click</button>' . "\n"
                    . $codeblock,
                'mustcontain' => ['onerror=', 'onclick=', '&lt;img', '&lt;button'],
                'mustnotcontain' => [],
            ],
            'multiple_code_blocks' => [
                'input' => 'HTML:' . "\n\n"
                    . $codeblock . 'html' . "\n"
                    . '<div>Test</div>' . "\n"
                    . $codeblock . "\n\n"
                    . 'JS:' . "\n\n"
                    . $codeblock . 'javascript' . "\n"
                    . 'alert(\'hi\');' . "\n"
                    . $codeblock,
                'mustcontain' => ['&lt;div&gt;', 'alert('],
                'mustnotcontain' => ['<div>Test</div>'],
            ],
            'special_characters_in_code' => [
                'input' => 'Example:' . "\n\n"
                    . $codeblock . "\n"
                    . '<>&"\'' . "\n"
                    . $codeblock,
                'mustcontain' => ['&lt;&gt;&amp;'],
                'mustnotcontain' => [],
            ],
            'windows_line_endings' => [
                'input' => "Code:\r\n\r\n" . $codeblock . "html\r\n<div>Test</div>\r\n" . $codeblock,
                'mustcontain' => ['&lt;div&gt;'],
                'mustnotcontain' => [],
            ],
            'mixed_content' => [
                'input' => '# Tutorial' . "\n\n"
                    . 'Here\'s how:' . "\n\n"
                    . '1. Write HTML' . "\n"
                    . '2. Add CSS' . "\n\n"
                    . $codeblock . 'html' . "\n"
                    . '<p>Hello</p>' . "\n"
                    . $codeblock . "\n\n"
                    . 'That\'s **all**!',
                'mustcontain' => ['<h1>', '<ol>', '&lt;p&gt;Hello&lt;/p&gt;', '<strong>all</strong>'],
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
                'input' => 'List:' . "\n" . "\n" . '- Item 1' . "\n" . '- Item 2' . "\n" . '- Item 3',
                'mustcontain' => ['<ul>', '<li>'],
            ],
            'ordered_list' => [
                'input' => 'Steps:' . "\n" . "\n" . '1. First' . "\n" . '2. Second' . "\n" . '3. Third',
                'mustcontain' => ['<ol>', '<li>'],
            ],
            'headings' => [
                'input' => '# Heading 1' . "\n" . "\n" . '## Heading 2' . "\n" . "\n" . '### Heading 3',
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
                'input' => $codeblock . 'php' . "\n" . 'echo \'Hello\';' . "\n" . $codeblock,
                'mustcontain' => ['<pre>', '<code'],
            ],
            'mathjax_inline_delimiters' => [
                'input' => 'The formula is \\(x^2 + y^2 = z^2\\)',
                'mustcontain' => ['\\(', '\\)'],
            ],
            'mathjax_display_delimiters' => [
                'input' => 'Display math: \\[E = mc^2\\]',
                'mustcontain' => ['\\[', '\\]'],
            ],
            'mathjax_inline_frac_delta_preserved' => [
                'input' => 'Formel: \\( v = \\frac{\\Delta x}{\\Delta t} \\)',
                'mustcontain' => ['\\( v = \\frac{\\Delta x}{\\Delta t} \\)'],
            ],
            'mathjax_inline_vec_preserved' => [
                'input' => 'Vektor: \\( \\vec{v} = \\frac{\\Delta \\vec{x}}{\\Delta t} \\)',
                'mustcontain' => ['\\vec{v}', '\\frac{\\Delta \\vec{x}}{\\Delta t}'],
            ],
            'mathjax_display_dollar_text_preserved' => [
                'input' => 'Umrechnung: $$1\\ \\text{m/s} = 3{,}6\\ \\text{km/h}$$',
                'mustcontain' => ['$$', '\\text{m/s}', '\\text{km/h}'],
            ],
            'mathjax_cdot_preserved' => [
                'input' => 'Kraft: \\( \\vec{F} = m \\cdot \\vec{a} \\)',
                'mustcontain' => ['\\vec{F}', '\\cdot', '\\vec{a}'],
            ],
            'mathjax_sqrt_preserved' => [
                'input' => 'Betrag: \\( |\\vec{v}| = \\sqrt{v_x^2 + v_y^2} \\)',
                'mustcontain' => ['\\sqrt{v_x^2 + v_y^2}', '\\vec{v}'],
            ],
            'mathjax_multiple_blocks_all_preserved' => [
                'input' => 'Erst \\( a = b \\) dann \\( c = d \\) und $$e = f$$',
                'mustcontain' => ['\\( a = b \\)', '\\( c = d \\)', '$$e = f$$'],
            ],
            'mathjax_rightarrow_quad_preserved' => [
                'input' => '\\( \\vec{F} = m\\vec{a} \\quad\\Rightarrow\\quad \\Delta\\vec{v} = \\frac{\\vec{F}}{m} \\)',
                'mustcontain' => ['\\Rightarrow', '\\quad', '\\frac{\\vec{F}}{m}'],
            ],
            'mathjax_mathrm_preserved' => [
                'input' => 'Einheit: \\( 1\\ \\mathrm{m/s} = 3{,}6\\ \\mathrm{km/h} \\)',
                'mustcontain' => ['\\mathrm{m/s}', '\\mathrm{km/h}'],
            ],
            'empty_input' => [
                'input' => '',
                'mustcontain' => [],
            ],
            'plain_text' => [
                'input' => 'Just plain text without any formatting.',
                'mustcontain' => ['Just plain text'],
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
     * Data provider for format_ai_markdown_output tests.
     *
     * Provides test cases for typical code blocks in various languages, ensuring that
     * HTML and potentially dangerous content inside code blocks is properly escaped,
     * while the surrounding markdown structure is correctly converted.
     *
     * @return array test cases with markdown input, mustcontain and mustnotcontain arrays
     */
    public static function format_ai_markdown_output_provider(): array {
        $codeblock = "\x60\x60\x60";
        $backtick = "\x60";

        return [
            'html_with_js_in_code_block' => [
                'input' => 'Here is some HTML with JavaScript:' . "\n\n"
                    . $codeblock . 'html' . "\n"
                    . '<!DOCTYPE html>' . "\n"
                    . '<html>' . "\n"
                    . '<head>' . "\n"
                    . '    <script>' . "\n"
                    . '        document.getElementById("demo").innerHTML = "Hello";' . "\n"
                    . '        alert("test");' . "\n"
                    . '    </script>' . "\n"
                    . '</head>' . "\n"
                    . '<body>' . "\n"
                    . '    <div class="container">' . "\n"
                    . '        <p onclick="evil()">Click me</p>' . "\n"
                    . '    </div>' . "\n"
                    . '</body>' . "\n"
                    . '</html>' . "\n"
                    . $codeblock,
                'mustcontain' => [
                    '<pre>',
                    '<code',
                    '&lt;html&gt;',
                    '&lt;script&gt;',
                    '&lt;/script&gt;',
                    '&lt;div class=',
                    '&lt;p onclick=',
                    'alert("test")',
                ],
                'mustnotcontain' => [
                    '<script>',
                    '<div class="container">',
                ],
            ],
            'python_code_in_code_block' => [
                'input' => 'A Python example:' . "\n\n"
                    . $codeblock . 'python' . "\n"
                    . 'import os' . "\n"
                    . '' . "\n"
                    . 'def greet(name: str) -> str:' . "\n"
                    . '    """Return a greeting."""' . "\n"
                    . '    return f"Hello, {name}!"' . "\n"
                    . '' . "\n"
                    . 'if __name__ == "__main__":' . "\n"
                    . '    print(greet("World"))' . "\n"
                    . $codeblock,
                'mustcontain' => [
                    '<pre>',
                    '<code class="python"',
                    'import os',
                    'def greet(name: str)',
                    'print(greet(',
                ],
                'mustnotcontain' => [],
            ],
            'java_code_in_code_block' => [
                'input' => 'A Java example:' . "\n\n"
                    . $codeblock . 'java' . "\n"
                    . 'public class HelloWorld {' . "\n"
                    . '    public static void main(String[] args) {' . "\n"
                    . '        System.out.println("Hello, World!");' . "\n"
                    . '    }' . "\n"
                    . '}' . "\n"
                    . $codeblock,
                'mustcontain' => [
                    '<pre>',
                    '<code class="java"',
                    'public class HelloWorld',
                    'System.out.println',
                ],
                'mustnotcontain' => [],
            ],
            'html_with_style_and_script_tags_in_code_block' => [
                'input' => 'Full HTML page:' . "\n\n"
                    . $codeblock . 'html' . "\n"
                    . '<style>' . "\n"
                    . '    .red { color: red; }' . "\n"
                    . '</style>' . "\n"
                    . '<div class="red">Styled</div>' . "\n"
                    . '<script src="https://evil.com/xss.js"></script>' . "\n"
                    . $codeblock,
                'mustcontain' => [
                    '&lt;style&gt;',
                    '&lt;div class=',
                    '&lt;script src=',
                    '<pre>',
                    '<code',
                ],
                'mustnotcontain' => [
                    '<style>',
                    '<script src=',
                    '<div class="red">',
                ],
            ],
            'plain_markdown_without_code_blocks' => [
                'input' => '# Title' . "\n\n"
                    . 'Some **bold** and *italic* text.' . "\n\n"
                    . '- Item 1' . "\n"
                    . '- Item 2',
                'mustcontain' => [
                    '<h1>',
                    '<strong>bold</strong>',
                    '<em>italic</em>',
                    '<ul>',
                    '<li>',
                ],
                'mustnotcontain' => [],
            ],
            'mixed_markdown_and_code_blocks' => [
                'input' => '## Setup' . "\n\n"
                    . 'Install the package:' . "\n\n"
                    . $codeblock . 'python' . "\n"
                    . 'pip install moodle-client' . "\n"
                    . $codeblock . "\n\n"
                    . 'Then create a **config file**:' . "\n\n"
                    . $codeblock . 'html' . "\n"
                    . '<form action="/submit" method="post">' . "\n"
                    . '    <input type="text" name="user">' . "\n"
                    . '</form>' . "\n"
                    . $codeblock,
                'mustcontain' => [
                    '<h2>',
                    '<strong>config file</strong>',
                    '<code class="python"',
                    'pip install moodle-client',
                    '&lt;form action=',
                    '&lt;input type=',
                ],
                'mustnotcontain' => [
                    '<form action=',
                    '<input type=',
                ],
            ],
            'options_are_passed_through' => [
                'input' => '**Bold** text.',
                'mustcontain' => ['<strong>Bold</strong>'],
                'mustnotcontain' => [],
                'options' => ['noclean' => true],
            ],
            'empty_input' => [
                'input' => '',
                'mustcontain' => [],
                'mustnotcontain' => [],
            ],
            'single_quotes_in_html_attributes_in_code_block' => [
                'input' => 'Example with single quotes:' . "\n\n"
                    . $codeblock . 'html' . "\n"
                    . '<div class=\'container\'>' . "\n"
                    . '    <a href=\'https://example.com\' title=\'It\\\'s a link\'>Click</a>' . "\n"
                    . '    <input type=\'text\' value=\'Hello World\'>' . "\n"
                    . '    <button onclick=\'alert("test")\'>Submit</button>' . "\n"
                    . '</div>' . "\n"
                    . $codeblock,
                'mustcontain' => [
                    '<pre>',
                    '<code',
                    '&lt;div class=',
                    '&lt;a href=',
                    '&lt;input type=',
                    '&lt;button onclick=',
                ],
                'mustnotcontain' => [
                    '<div class=',
                    '<button onclick=',
                ],
            ],
            'fenced_code_block_with_lang_in_list_loose' => [
                'input' => '*   **HTML:**' . "\n"
                    . '    ' . $codeblock . 'html' . "\n"
                    . '    <div>hello</div>' . "\n"
                    . '    ' . $codeblock . "\n"
                    . "\n"
                    . '*   **Python:**' . "\n"
                    . '    ' . $codeblock . 'python' . "\n"
                    . '    print("hi")' . "\n"
                    . '    ' . $codeblock,
                'mustcontain' => [
                    '<pre>',
                    '<code class="html"',
                    '&lt;div&gt;hello&lt;/div&gt;',
                    '<code class="python"',
                    'print("hi")',
                ],
                'mustnotcontain' => [
                    '<div>hello</div>',
                    $codeblock . 'html',
                    $codeblock . 'python',
                ],
            ],
            'fenced_code_block_with_lang_in_list_tight' => [
                'input' => '*   **HTML:**' . "\n"
                    . '    ' . $codeblock . 'html' . "\n"
                    . '    <div>hello</div>' . "\n"
                    . '    ' . $codeblock . "\n"
                    . '*   **Python:**' . "\n"
                    . '    ' . $codeblock . 'python' . "\n"
                    . '    print("hi")' . "\n"
                    . '    ' . $codeblock,
                'mustcontain' => [
                    '<pre>',
                    '<code class="html"',
                    '&lt;div&gt;hello&lt;/div&gt;',
                    '<code class="python"',
                    'print("hi")',
                ],
                'mustnotcontain' => [
                    '<div>hello</div>',
                    $codeblock . 'html',
                    $codeblock . 'python',
                ],
            ],
            'fenced_code_block_without_lang_in_list_tight' => [
                'input' => '* Item 1:' . "\n"
                    . '    ' . $codeblock . "\n"
                    . '    echo "hello";' . "\n"
                    . '    ' . $codeblock . "\n"
                    . '* Item 2:' . "\n"
                    . '    ' . $codeblock . "\n"
                    . '    print("world")' . "\n"
                    . '    ' . $codeblock,
                'mustcontain' => [
                    '<pre>',
                    '<code>',
                    'echo "hello";',
                    'print("world")',
                ],
                'mustnotcontain' => [
                    $codeblock,
                ],
            ],
            'inline_code_not_wrapped_in_pre' => [
                'input' => 'Use ' . $backtick . 'echo "hello"' . $backtick
                    . ' to print and ' . $backtick . 'print("world")' . $backtick . ' in Python.',
                'mustcontain' => [
                    '<code>echo "hello"</code>',
                    '<code>print("world")</code>',
                ],
                'mustnotcontain' => [
                    '<pre><code>echo',
                    '<pre><code>print',
                ],
            ],
            'mixed_list_code_blocks_and_inline_code' => [
                'input' => 'Run ' . $backtick . 'npm install' . $backtick . ' first, then:' . "\n\n"
                    . '*   **JavaScript:**' . "\n"
                    . '    ' . $codeblock . 'javascript' . "\n"
                    . '    console.log("Hello");' . "\n"
                    . '    ' . $codeblock . "\n"
                    . '*   **Python:**' . "\n"
                    . '    ' . $codeblock . 'python' . "\n"
                    . '    print("Hello")' . "\n"
                    . '    ' . $codeblock . "\n\n"
                    . 'Use ' . $backtick . 'node app.js' . $backtick . ' to start.',
                'mustcontain' => [
                    '<code>npm install</code>',
                    '<code>node app.js</code>',
                    '<code class="javascript"',
                    'console.log("Hello")',
                    '<code class="python"',
                    'print("Hello")',
                    '<pre>',
                ],
                'mustnotcontain' => [
                    '<pre><code>npm install',
                    '<pre><code>node app.js',
                    $codeblock . 'javascript',
                    $codeblock . 'python',
                ],
            ],
        ];
    }

    /**
     * Test that format_ai_markdown_output correctly converts markdown with code blocks.
     *
     * @param string $input The markdown input
     * @param array $mustcontain Strings that must be in the output
     * @param array $mustnotcontain Strings that must not be in the output
     * @param array $options Optional format_text options to pass
     * @covers \local_ai_manager\base_purpose::format_ai_markdown_output
     * @dataProvider format_ai_markdown_output_provider
     */
    public function test_format_ai_markdown_output(
        string $input,
        array $mustcontain,
        array $mustnotcontain,
        array $options = []
    ): void {
        $purpose = new base_purpose();
        $output = $purpose->format_ai_markdown_output($input, $options);

        foreach ($mustcontain as $expected) {
            $this->assertStringContainsString($expected, $output);
        }
        foreach ($mustnotcontain as $notexpected) {
            $this->assertStringNotContainsString($notexpected, $output);
        }
    }
}

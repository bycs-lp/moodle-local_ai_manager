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

use advanced_testcase;
use core_plugin_manager;
use local_ai_manager\local\connector_factory;

/**
 * Tests for {@see \local_ai_manager\base_purpose}.
 *
 * Covers the full Markdown to safe HTML pipeline as used for AI Manager output.
 *
 * Test surfaces:
 *  - happy-path Markdown rendering (headings, bold, italic, lists, links, blockquotes),
 *  - XSS / HTML neutralisation outside code regions,
 *  - code-block rendering (Markdown fences AND LLM-emitted pre/code HTML),
 *  - list rendering (tight vs loose, with embedded fenced code blocks),
 *  - MathJax preservation across the entire pipeline,
 *  - white-box stage-level tests for placeholder prefix generation and the
 *    MathJax environment escaper,
 *  - a dedicated regression suite that captures every known MBS-10767 failure mode,
 *  - end-to-end realistic scenarios derived from production tickets.
 *
 * @package    local_ai_manager
 * @copyright  2025 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \local_ai_manager\base_purpose
 */
final class base_purpose_test extends advanced_testcase {
    /**
     * Triple-backtick fence used in the providers. Built via chr() to satisfy
     * the moodle.Strings.ForbiddenStrings rule, which forbids literal backticks
     * inside PHP strings.
     *
     * @return string
     */
    private static function fence(): string {
        return str_repeat(chr(96), 3);
    }

    /**
     * Single backtick used in the providers. Built via chr() to satisfy
     * the moodle.Strings.ForbiddenStrings rule.
     *
     * @return string
     */
    private static function tick(): string {
        return chr(96);
    }

    // -----------------------------------------------------------------
    // Section 1: Misc base-class behaviour (purpose descriptions).

    /**
     * Every installed purpose must either define purposedescription in its lang
     * file or override get_description() on its own purpose class.
     *
     * @covers ::get_description
     * @dataProvider get_description_provider
     * @param string $purpose Purpose plugin name (e.g. "chat", "feedback", "agent").
     */
    public function test_get_description(string $purpose): void {
        $connectorfactory = \core\di::get(connector_factory::class);
        $purposeinstance = $connectorfactory->get_purpose_by_purpose_string($purpose);
        $reflector = new \ReflectionMethod($purposeinstance, 'get_description');
        $ismethodoverwritten = $reflector->getDeclaringClass()->getName() === get_class($purposeinstance);
        if (!$ismethodoverwritten) {
            $stringmanager = get_string_manager();
            $this->assertTrue(
                $stringmanager->string_exists('purposedescription', 'aipurpose_' . $purpose),
                "Purpose '{$purpose}' must define a 'purposedescription' lang string."
            );
            $this->assertEquals(
                get_string('purposedescription', 'aipurpose_' . $purpose),
                $purposeinstance->get_description(),
                "get_description() must return the localized 'purposedescription' string for purpose '{$purpose}'."
            );
        } else {
            $this->assertNotEmpty(
                $purposeinstance->get_description(),
                "Overridden get_description() must return a non-empty string for purpose '{$purpose}'."
            );
        }
    }

    /**
     * Data provider yielding every installed purpose plugin name.
     *
     * @return array<string, array{purpose: string}>
     */
    public static function get_description_provider(): array {
        $cases = [];
        foreach (array_keys(core_plugin_manager::instance()->get_installed_plugins('aipurpose')) as $purpose) {
            $cases['test_get_description_of_purpose_' . $purpose] = ['purpose' => $purpose];
        }
        return $cases;
    }

    // -----------------------------------------------------------------
    // Section 2: Markdown happy-path rendering.

    /**
     * Standard Markdown constructs must produce the expected HTML structures.
     *
     * @covers ::format_output
     * @covers ::format_ai_markdown_output
     * @dataProvider markdown_rendering_provider
     * @param string $input Markdown input.
     * @param string[] $mustcontain Substrings that must appear.
     */
    public function test_markdown_rendering(string $input, array $mustcontain): void {
        $purpose = new base_purpose();
        $output = $purpose->format_output($input);
        foreach ($mustcontain as $expected) {
            $this->assertStringContainsString($expected, $output, "Missing '{$expected}'. Got:\n{$output}");
        }
    }

    /**
     * Data provider for happy-path Markdown rendering tests.
     *
     * @return array<string, array{input: string, mustcontain: string[]}>
     */
    public static function markdown_rendering_provider(): array {
        return [
            'bold' => ['input' => 'This is **bold**.', 'mustcontain' => ['<strong>bold</strong>']],
            'italic' => ['input' => 'This is *italic*.', 'mustcontain' => ['<em>italic</em>']],
            'bold_and_italic_combined' => [
                'input' => 'Here is **bold** and *italic* text.',
                'mustcontain' => ['<strong>bold</strong>', '<em>italic</em>'],
            ],
            'heading_h1' => ['input' => '# Big', 'mustcontain' => ['<h1>']],
            'heading_h2' => ['input' => '## Mid', 'mustcontain' => ['<h2>']],
            'heading_h3' => ['input' => '### Small', 'mustcontain' => ['<h3>']],
            'all_three_headings' => [
                'input' => "# Heading 1\n\n## Heading 2\n\n### Heading 3",
                'mustcontain' => ['<h1>', '<h2>', '<h3>'],
            ],
            'link_external' => [
                'input' => 'See [Moodle](https://moodle.org) for more.',
                'mustcontain' => ['href="https://moodle.org"', '>Moodle</a>'],
            ],
            'blockquote_simple' => ['input' => '> A quote.', 'mustcontain' => ['<blockquote>']],
            'unordered_list_tight' => [
                // Stage 3 of the pipeline loosens the first item, so MarkdownExtra emits
                // each li wrapped in p. That is the documented behaviour and acceptable.
                'input' => "- Alpha\n- Beta\n- Gamma",
                'mustcontain' => ['<ul>', '<li>', 'Alpha', 'Beta', 'Gamma'],
            ],
            'unordered_list_loose' => [
                'input' => "- A\n\n- B\n\n- C",
                'mustcontain' => ['<ul>', '<li>'],
            ],
            'ordered_list' => [
                'input' => "1. First\n2. Second\n3. Third",
                'mustcontain' => ['<ol>', '<li>', 'First', 'Second', 'Third'],
            ],
            'plain_text_paragraph' => [
                'input' => 'Just plain text without any formatting.',
                'mustcontain' => ['Just plain text'],
            ],
        ];
    }

    // -----------------------------------------------------------------
    // Section 3: XSS / HTML escaping invariants outside code regions.

    /**
     * Raw HTML tags outside code regions must be neutralized via entity escaping.
     *
     * @covers ::format_output
     * @covers ::format_ai_markdown_output
     * @dataProvider xss_outside_code_provider
     * @param string $input The input string.
     * @param string[] $mustcontain Substrings that must appear.
     * @param string[] $mustnotcontain Substrings that must not appear.
     */
    public function test_xss_outside_code_is_neutralized(
        string $input,
        array $mustcontain,
        array $mustnotcontain
    ): void {
        $purpose = new base_purpose();
        $output = $purpose->format_output($input);
        foreach ($mustcontain as $expected) {
            $this->assertStringContainsString($expected, $output, "Missing '{$expected}'. Got:\n{$output}");
        }
        foreach ($mustnotcontain as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $output, "Forbidden '{$forbidden}' present. Got:\n{$output}");
        }
    }

    /**
     * Data provider for XSS / HTML escaping tests outside code regions.
     *
     * @return array<string, array{input: string, mustcontain: string[], mustnotcontain: string[]}>
     */
    public static function xss_outside_code_provider(): array {
        return [
            'raw_script_outside_code_block' => [
                'input' => 'Hello <script>alert(\'xss\')</script> world',
                'mustcontain' => ['Hello', 'world', '&lt;script&gt;', '&lt;/script&gt;'],
                'mustnotcontain' => ['<script>'],
            ],
            'script_with_double_quotes' => [
                'input' => 'Hello <script>alert("xss")</script> world.',
                'mustcontain' => ['Hello', 'world.', '&lt;script&gt;', '&lt;/script&gt;'],
                'mustnotcontain' => ['<script>'],
            ],
            'img_onerror_xss' => [
                'input' => 'Watch out: <img src=x onerror="evil()">',
                'mustcontain' => ['&lt;img'],
                'mustnotcontain' => ['<img src'],
            ],
            'svg_onload_payload' => [
                'input' => 'Image: <svg onload="alert(\'xss\')"><circle r="50"/></svg>',
                'mustcontain' => ['&lt;svg'],
                'mustnotcontain' => ['<svg onload', '<svg>'],
            ],
            'plain_p_tags_outside_code_escaped' => [
                'input' => 'Body: <p>Hello</p>',
                'mustcontain' => ['&lt;p&gt;Hello&lt;/p&gt;'],
                'mustnotcontain' => ['Body: <p>Hello</p>'],
            ],
            'multiple_html_tags_outside_code_block' => [
                'input' => 'Use <div> and </div> and <span class="test"> elements',
                'mustcontain' => ['&lt;div&gt;', '&lt;/div&gt;', '&lt;span class='],
                'mustnotcontain' => ['<div>', '</div>', '<span class='],
            ],
            'iframe_payload' => [
                'input' => 'Embed: <iframe src="https://evil.example/"></iframe>',
                'mustcontain' => ['&lt;iframe'],
                'mustnotcontain' => ['<iframe '],
            ],
            'event_handler_outside_code' => [
                'input' => 'Click <button onclick="evil()">me</button>!',
                'mustcontain' => ['&lt;button', 'onclick='],
                'mustnotcontain' => ['<button onclick='],
            ],
        ];
    }

    // -----------------------------------------------------------------
    // Section 4: Code-block rendering (Markdown fences AND raw pre/code HTML).

    /**
     * Code-block tests: language class survives, entities stay encoded, dangerous
     * payloads inside code blocks become inert text. Note that PHP MarkdownExtra
     * emits class="xxx" (NOT class="language-xxx") on code tags.
     *
     * @covers ::format_output
     * @covers ::format_ai_markdown_output
     * @dataProvider code_block_provider
     * @param string $input The input string.
     * @param string[] $mustcontain Substrings that must appear in the output.
     * @param string[] $mustnotcontain Substrings that must not appear in the output.
     */
    public function test_code_block_rendering(
        string $input,
        array $mustcontain,
        array $mustnotcontain
    ): void {
        $purpose = new base_purpose();
        $output = $purpose->format_output($input);
        foreach ($mustcontain as $expected) {
            $this->assertStringContainsString($expected, $output, "Missing '{$expected}'. Got:\n{$output}");
        }
        foreach ($mustnotcontain as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $output, "Forbidden '{$forbidden}' present. Got:\n{$output}");
        }
    }

    /**
     * Data provider for code-block rendering tests.
     *
     * @return array<string, array{input: string, mustcontain: string[], mustnotcontain: string[]}>
     */
    public static function code_block_provider(): array {
        $fence = self::fence();
        $tick = self::tick();
        return [
            'fenced_block_no_language' => [
                'input' => "{$fence}\nplain code\n{$fence}",
                'mustcontain' => ['<pre>', '<code>', 'plain code'],
                'mustnotcontain' => [],
            ],
            'fenced_block_python' => [
                'input' => "Example:\n\n{$fence}python\nprint(\"hi\")\n{$fence}",
                'mustcontain' => ['<pre>', '<code class="python"', 'print("hi")'],
                'mustnotcontain' => [],
            ],
            'fenced_block_javascript' => [
                'input' => "Demo:\n\n{$fence}javascript\nconsole.log('x');\n{$fence}",
                'mustcontain' => ['<pre>', '<code class="javascript"', "console.log('x');"],
                'mustnotcontain' => [],
            ],
            'fenced_block_java' => [
                'input' => "Demo:\n\n{$fence}java\npublic class HelloWorld { }\n{$fence}",
                'mustcontain' => ['<pre>', '<code class="java"', 'public class HelloWorld'],
                'mustnotcontain' => [],
            ],
            'html_inside_code_block_is_escaped_as_entities' => [
                'input' => "Demo:\n\n{$fence}python\nprint(\"<html>\")\n{$fence}",
                'mustcontain' => ['<pre>', '&lt;html&gt;'],
                'mustnotcontain' => ['<html>'],
            ],
            'html_with_style_and_script_in_code_block' => [
                'input' => "Demo:\n\n{$fence}html\n<style>.x{color:red}</style>\n"
                    . "<div>y</div>\n<script src=\"evil.js\"></script>\n{$fence}",
                'mustcontain' => ['<pre>', '&lt;style&gt;', '&lt;div', '&lt;script src='],
                'mustnotcontain' => ['<style>', '<div>y</div>', '<script src='],
            ],
            'c_code_keeps_include_as_text_not_heading' => [
                'input' => "C example:\n\n{$fence}c\n#include <stdio.h>\nint main(void){return 0;}\n{$fence}",
                'mustcontain' => ['<pre>', '<code class="c"', '#include &lt;stdio.h&gt;'],
                'mustnotcontain' => ['<h1>', '<h2>'],
            ],
            'event_handlers_in_code_block_are_inert' => [
                'input' => "Demo:\n\n{$fence}html\n<img src=\"x\" onerror=\"alert('xss')\">\n"
                    . "<button onclick=\"evil()\">Click</button>\n{$fence}",
                'mustcontain' => ['&lt;img', '&lt;button', 'onerror=', 'onclick='],
                'mustnotcontain' => ['<img src', '<button onclick'],
            ],
            'special_characters_inside_code_block' => [
                'input' => "Demo:\n\n{$fence}\n<>&\"'\n{$fence}",
                'mustcontain' => ['&lt;&gt;&amp;'],
                'mustnotcontain' => [],
            ],
            'single_quotes_in_html_attributes_in_code_block' => [
                'input' => "Demo:\n\n{$fence}html\n<a href='https://example.com' title='link'>Click</a>\n{$fence}",
                'mustcontain' => ['<pre>', '&lt;a href=', 'https://example.com'],
                'mustnotcontain' => ['<a href='],
            ],
            'multiple_code_blocks_different_languages' => [
                'input' => "HTML:\n\n{$fence}html\n<div>Test</div>\n{$fence}\n\n"
                    . "JS:\n\n{$fence}javascript\nalert('hi');\n{$fence}",
                'mustcontain' => ['&lt;div&gt;', "alert('hi')", '<code class="html"', '<code class="javascript"'],
                'mustnotcontain' => ['<div>Test</div>'],
            ],
            'inline_code_html_escaped' => [
                'input' => "Use the {$tick}<div>{$tick} element for containers.",
                'mustcontain' => ['&lt;div&gt;', '<code>'],
                'mustnotcontain' => [],
            ],
            'inline_code_script_escaped' => [
                'input' => "Never use {$tick}<script>alert('xss')</script>{$tick} inline.",
                'mustcontain' => ['&lt;script&gt;', '<code>'],
                'mustnotcontain' => ['<script>alert'],
            ],
            'inline_code_not_wrapped_in_pre' => [
                'input' => "Run {$tick}npm install{$tick} first.",
                'mustcontain' => ['<code>npm install</code>'],
                'mustnotcontain' => ['<pre><code>npm install'],
            ],
            'html_pre_code_input_is_converted_and_preserved' => [
                'input' => 'Demo: <pre><code class="language-python">print("hi")</code></pre>',
                'mustcontain' => ['<pre>', '<code class="python"', 'print("hi")'],
                'mustnotcontain' => [],
            ],
            'html_pre_code_javascript' => [
                'input' => 'Demo: <pre><code class="language-javascript">console.log("x");</code></pre>',
                'mustcontain' => ['<pre>', '<code class="javascript"', 'console.log("x")'],
                'mustnotcontain' => [],
            ],
            'windows_line_endings_in_code_block' => [
                'input' => "Code:\r\n\r\n{$fence}html\r\n<div>Test</div>\r\n{$fence}",
                'mustcontain' => ['&lt;div&gt;'],
                'mustnotcontain' => ['<div>Test</div>'],
            ],
            'mixed_headings_lists_code_block' => [
                'input' => "# Tutorial\n\nHere's how:\n\n1. Write HTML\n2. Add CSS\n\n"
                    . "{$fence}html\n<p>Hello</p>\n{$fence}\n\nThat's **all**!",
                'mustcontain' => ['<h1>', '<ol>', '&lt;p&gt;Hello&lt;/p&gt;', '<strong>all</strong>'],
                'mustnotcontain' => [],
            ],
        ];
    }

    // -----------------------------------------------------------------
    // Section 5: List rendering - tight vs loose, with embedded code blocks.

    /**
     * Tight lists must stay coherent (single ul), loose lists too. Emphasis
     * markers like asterisk-bold-asterisk after a newline must NEVER become
     * list items. Note that MarkdownExtra wraps each li content in p when
     * the list is loose (which it always is after our Stage 3 normalisation).
     *
     * @covers ::format_output
     * @covers ::format_ai_markdown_output
     * @dataProvider list_rendering_provider
     * @param string $input The input string.
     * @param string[] $mustcontain Substrings that must appear.
     * @param string[] $mustnotcontain Substrings that must not appear.
     */
    public function test_list_rendering(string $input, array $mustcontain, array $mustnotcontain): void {
        $purpose = new base_purpose();
        $output = $purpose->format_output($input);
        foreach ($mustcontain as $expected) {
            $this->assertStringContainsString($expected, $output, "Missing '{$expected}'. Got:\n{$output}");
        }
        foreach ($mustnotcontain as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $output, "Forbidden '{$forbidden}' present. Got:\n{$output}");
        }
    }

    /**
     * Data provider for list rendering tests.
     *
     * @return array<string, array{input: string, mustcontain: string[], mustnotcontain: string[]}>
     */
    public static function list_rendering_provider(): array {
        $fence = self::fence();
        return [
            'tight_unordered_list_renders_as_one_ul' => [
                'input' => "- Alpha\n- Beta\n- Gamma",
                'mustcontain' => ['<ul>', '<li>', 'Alpha', 'Beta', 'Gamma'],
                'mustnotcontain' => ["</ul>\n<ul>"],
            ],
            'tight_list_with_intro_paragraph' => [
                'input' => "Items follow:\n- One\n- Two\n- Three",
                'mustcontain' => ['<ul>', '<li>', 'One', 'Two', 'Three'],
                'mustnotcontain' => ["</ul>\n<ul>"],
            ],
            'loose_unordered_list' => [
                'input' => "- A\n\n- B\n\n- C",
                'mustcontain' => ['<ul>', '<li>'],
                'mustnotcontain' => [],
            ],
            'tight_ordered_list' => [
                'input' => "1. First\n2. Second\n3. Third",
                'mustcontain' => ['<ol>', '<li>', 'First', 'Second', 'Third'],
                'mustnotcontain' => [],
            ],
            'emphasis_after_newline_is_not_a_list_marker' => [
                'input' => "Important fact.\n*Italic line continues.*",
                'mustcontain' => ['<em>Italic line continues.</em>'],
                'mustnotcontain' => ['<ul>', '<li>'],
            ],
            'list_item_with_python_fence' => [
                'input' => "- HTML:\n  {$fence}html\n  <div>x</div>\n  {$fence}\n"
                    . "- Python:\n  {$fence}python\n  print(\"x\")\n  {$fence}",
                'mustcontain' => ['<ul>', '<li>', '<pre>', '<code class="html"', '<code class="python"'],
                'mustnotcontain' => [],
            ],
            'list_item_with_fenced_block_no_language' => [
                'input' => "* Item 1:\n    {$fence}\n    echo \"hello\";\n    {$fence}\n"
                    . "* Item 2:\n    {$fence}\n    print(\"world\")\n    {$fence}",
                'mustcontain' => ['<pre>', '<code>', 'echo "hello";', 'print("world")'],
                'mustnotcontain' => [$fence],
            ],
            'numbered_list_with_paren_renders_as_single_paragraph' => [
                // PHP MarkdownExtra does not recognise "1)" as list syntax. It joins
                // consecutive "1) ... 2) ... 3) ..." lines into a single p tag.
                'input' => "Changes:\n1) First.\n2) Second.\n3) Third.",
                'mustcontain' => ['<p>', '1) First.', '2) Second.', '3) Third.'],
                'mustnotcontain' => [],
            ],
        ];
    }

    // -----------------------------------------------------------------
    // Section 6: MathJax preservation across the entire pipeline.

    /**
     * Every LaTeX / MathJax fragment the LLM emits must survive the pipeline
     * verbatim. Placeholder leakage is forbidden in any case.
     *
     * @covers ::format_output
     * @covers ::format_ai_markdown_output
     * @dataProvider mathjax_provider
     * @param string $input The input string.
     * @param string[] $mustcontain Substrings that must appear.
     * @param string[] $mustnotcontain Substrings that must not appear.
     */
    public function test_mathjax_preservation(string $input, array $mustcontain, array $mustnotcontain): void {
        $purpose = new base_purpose();
        $output = $purpose->format_output($input);
        foreach ($mustcontain as $expected) {
            $this->assertStringContainsString($expected, $output, "Missing '{$expected}'. Got:\n{$output}");
        }
        foreach ($mustnotcontain as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $output, "Forbidden '{$forbidden}' present. Got:\n{$output}");
        }
    }

    /**
     * Data provider for MathJax preservation tests.
     *
     * @return array<string, array{input: string, mustcontain: string[], mustnotcontain: string[]}>
     */
    public static function mathjax_provider(): array {
        $fence = self::fence();
        return [
            'inline_paren_simple' => [
                'input' => 'The formula \\(x^2 + y^2 = z^2\\)',
                'mustcontain' => ['\\(x^2 + y^2 = z^2\\)'],
                'mustnotcontain' => ['AIMATHJAXPLACEHOLDER'],
            ],
            'inline_paren_with_frac' => [
                'input' => 'Formel: \\( v = \\frac{\\Delta x}{\\Delta t} \\)',
                'mustcontain' => ['\\( v = \\frac{\\Delta x}{\\Delta t} \\)'],
                'mustnotcontain' => ['AIMATHJAXPLACEHOLDER'],
            ],
            'inline_paren_vec' => [
                'input' => 'Vektor: \\( \\vec{v} = \\frac{\\Delta \\vec{x}}{\\Delta t} \\)',
                'mustcontain' => ['\\vec{v}', '\\frac', '\\Delta \\vec{x}'],
                'mustnotcontain' => ['AIMATHJAXPLACEHOLDER'],
            ],
            'inline_paren_sqrt' => [
                'input' => 'Betrag: \\( |\\vec{v}| = \\sqrt{v_x^2 + v_y^2} \\)',
                'mustcontain' => ['\\sqrt{v_x^2 + v_y^2}', '\\vec{v}'],
                'mustnotcontain' => ['AIMATHJAXPLACEHOLDER'],
            ],
            'inline_paren_rightarrow_quad' => [
                'input' => '\\( \\vec{F} = m\\vec{a} \\quad\\Rightarrow\\quad '
                    . '\\Delta\\vec{v} = \\frac{\\vec{F}}{m}\\,\\Delta t \\)',
                'mustcontain' => ['\\Rightarrow', '\\quad', '\\frac{\\vec{F}}{m}'],
                'mustnotcontain' => ['AIMATHJAXPLACEHOLDER'],
            ],
            'multiple_inline_blocks_in_same_paragraph' => [
                'input' => 'Gegeben \\( \\vec{v}_1 \\) und \\( \\vec{v}_2 \\), '
                    . 'dann \\( \\Delta \\vec{v} = \\vec{v}_2 - \\vec{v}_1 \\).',
                'mustcontain' => ['\\( \\vec{v}_1 \\)', '\\( \\vec{v}_2 \\)', '\\Delta \\vec{v}'],
                'mustnotcontain' => ['AIMATHJAXPLACEHOLDER'],
            ],
            'mathrm_command' => [
                'input' => '\\( 1\\ \\mathrm{m/s} = \\frac{3600\\ \\mathrm{m}}{3600\\ \\mathrm{s}} '
                    . '= 3{,}6\\ \\mathrm{km/h} \\)',
                'mustcontain' => ['\\mathrm{m/s}', '\\mathrm{km/h}', '\\frac'],
                'mustnotcontain' => ['AIMATHJAXPLACEHOLDER'],
            ],
            'display_bracket_cdot' => [
                'input' => 'Kraft: \\[ \\vec{F} = m \\cdot \\vec{a} \\]',
                'mustcontain' => ['\\vec{F}', '\\cdot', '\\vec{a}', '\\[', '\\]'],
                'mustnotcontain' => ['AIMATHJAXPLACEHOLDER'],
            ],
            'display_dollar_text_command' => [
                'input' => 'Umrechnung: $$1\\ \\text{m/s} = 3{,}6\\ \\text{km/h}$$',
                'mustcontain' => ['$$1\\ \\text{m/s} = 3{,}6\\ \\text{km/h}$$'],
                'mustnotcontain' => ['AIMATHJAXPLACEHOLDER'],
            ],
            'mixed_inline_and_display_dollar' => [
                'input' => "Inline: \\( x^2 \\)\n\nDisplay: \$\$y = mx + b\$\$\n\nNoch: \\( z = 5 \\)",
                'mustcontain' => ['\\( x^2 \\)', '$$y = mx + b$$', '\\( z = 5 \\)'],
                'mustnotcontain' => ['AIMATHJAXPLACEHOLDER'],
            ],
            'mathjax_with_bold_around' => [
                'input' => 'Die **Skalargeschwindigkeit** ist \\( v = \\frac{\\Delta x}{\\Delta t} \\) (Einheit m/s).',
                'mustcontain' => ['<strong>Skalargeschwindigkeit</strong>', '\\frac', '\\Delta'],
                'mustnotcontain' => ['AIMATHJAXPLACEHOLDER'],
            ],
            'mathjax_in_heading' => [
                'input' => "## Konkrete Korrekturen\n\nFormel: \\( \\vec{v} = \\frac{\\Delta\\vec{x}}{\\Delta t} \\)",
                'mustcontain' => ['<h2>', '\\vec{v}', '\\frac'],
                'mustnotcontain' => ['AIMATHJAXPLACEHOLDER'],
            ],
            'mathjax_in_list' => [
                'input' => "Formeln:\n\n"
                    . "- Geschwindigkeit: \\( v = \\frac{\\Delta x}{\\Delta t} \\)\n"
                    . "- Beschleunigung: \\( a = \\frac{\\Delta v}{\\Delta t} \\)\n"
                    . "- Kraft: \\( F = m \\cdot a \\)\n",
                'mustcontain' => ['<li>', '\\frac', '\\cdot'],
                'mustnotcontain' => ['AIMATHJAXPLACEHOLDER'],
            ],
            'mathjax_begin_end_environment_outside_pre_is_wrapped' => [
                'input' => 'See \\begin{equation} x \\end{equation} below.',
                'mustcontain' => ['mathjax_ignore', '\\begin{equation}', '\\end{equation}'],
                'mustnotcontain' => [],
            ],
            'mathjax_begin_end_environment_inside_code_block_is_not_wrapped' => [
                'input' => "Demo:\n\n{$fence}latex\n\\begin{equation}x\\end{equation}\n{$fence}",
                'mustcontain' => ['<pre>', '\\begin{equation}', '\\end{equation}'],
                'mustnotcontain' => ['mathjax_ignore'],
            ],
            'mathjax_begin_equation_escape_acceptance_test' => [
                'input' => 'Use \\begin{equation} for numbered math.',
                'mustcontain' => ['\\begin{equation}'],
                'mustnotcontain' => [],
            ],
        ];
    }

    /**
     * End-to-end test that mimics a real production AI feedback excerpt (see MBS-10777).
     *
     * @covers ::format_output
     * @covers ::format_ai_markdown_output
     */
    public function test_realistic_production_feedback_with_mathjax(): void {
        $input = "## Verbesserungen\n\n"
            . "1. Mathematische Schreibweise vereinheitlichen.\n"
            . "2. Vektorformeln deutlicher zeigen.\n\n"
            . "## Konkrete Korrekturen\n\n"
            . "- Schreibe die Definition klarer:\n\n"
            . "  \\( v = \\frac{\\Delta x}{\\Delta t} \\)\n\n"
            . "- Vektorform:\n\n"
            . "  \\( \\vec{v} = \\frac{\\Delta \\vec{x}}{\\Delta t} \\)\n\n"
            . "- Umrechnungen:\n\n"
            . "  \$\$1\\ \\text{m/s} = 3{,}6\\ \\text{km/h}\$\$\n\n"
            . "- Geschwindigkeitsänderung:\n\n"
            . "  \\( \\Delta \\vec{v} = \\vec{v}_2 - \\vec{v}_1 \\)\n\n"
            . "- Beschleunigung und Kraft:\n\n"
            . "  \\( \\vec{a} = \\frac{\\Delta \\vec{v}}{\\Delta t} \\)\n\n"
            . "  \\( \\vec{F} = m \\cdot \\vec{a} \\)\n";

        $purpose = new base_purpose();
        $output = $purpose->format_output($input);

        $this->assertStringContainsString('<h2>', $output, 'The h2 heading must render.');
        $this->assertStringContainsString('<ol>', $output, 'Numbered list must render as ol.');
        $this->assertStringContainsString('<li>', $output, 'List items must render.');

        $this->assertStringContainsString('\\frac{\\Delta x}{\\Delta t}', $output, 'LaTeX frac must be preserved.');
        $this->assertStringContainsString('\\vec{v}', $output, 'LaTeX vec must be preserved.');
        $this->assertStringContainsString('\\text{m/s}', $output, 'LaTeX text m/s must be preserved.');
        $this->assertStringContainsString('\\text{km/h}', $output, 'LaTeX text km/h must be preserved.');
        $this->assertStringContainsString('\\vec{v}_2 - \\vec{v}_1', $output, 'LaTeX subscripted vectors must be preserved.');
        $this->assertStringContainsString('\\cdot', $output, 'LaTeX cdot must be preserved.');

        $this->assertStringContainsString('\\(', $output, 'Inline math delimiter must be intact.');
        $this->assertStringContainsString('\\)', $output, 'Inline math delimiter must be intact.');
        $this->assertStringContainsString('$$', $output, 'Display math delimiter must be intact.');

        $this->assertStringNotContainsString('AIMATHJAXPLACEHOLDER', $output, 'MathJax placeholder must not leak to output.');
    }

    // -----------------------------------------------------------------
    // Section 7: White-box stage-level tests for individual helpers.

    /**
     * The generate_placeholder_prefix() method must always return a prefix not
     * present in the input.
     *
     * @covers ::generate_placeholder_prefix
     * @dataProvider placeholder_prefix_provider
     * @param string $input The input string.
     * @param string $expected The expected placeholder prefix.
     */
    public function test_generate_placeholder_prefix(string $input, string $expected): void {
        $prefix = base_purpose::generate_placeholder_prefix($input);
        $this->assertEquals($expected, $prefix, 'Generated prefix must match the expected value.');
        $this->assertStringNotContainsString($prefix, $input, 'Generated prefix must not occur in the input.');
    }

    /**
     * Data provider for placeholder prefix generator tests.
     *
     * @return array<string, array{input: string, expected: string}>
     */
    public static function placeholder_prefix_provider(): array {
        return [
            'plain_text' => [
                'input' => 'Just some normal text without anything special.',
                'expected' => "\x00PLACEHOLDER",
            ],
            'text_contains_default_placeholder' => [
                'input' => "before \x00PLACEHOLDER after",
                'expected' => "\x00PLACEHOLDER" . 'X',
            ],
            'text_contains_extended_placeholder' => [
                'input' => "Both \x00PLACEHOLDER and \x00PLACEHOLDERX appear here.",
                'expected' => "\x00PLACEHOLDER" . 'XX',
            ],
            'empty_input' => [
                'input' => '',
                'expected' => "\x00PLACEHOLDER",
            ],
        ];
    }

    /**
     * The escape_mathjax_environments() method must wrap patterns outside pre but
     * leave them untouched inside pre.
     *
     * @covers ::escape_mathjax_environments
     * @dataProvider escape_mathjax_environments_provider
     * @param string $input The HTML input.
     * @param string[] $mustcontain Substrings that must appear.
     * @param string[] $mustnotcontain Substrings that must not appear.
     */
    public function test_escape_mathjax_environments(
        string $input,
        array $mustcontain,
        array $mustnotcontain
    ): void {
        $output = base_purpose::escape_mathjax_environments($input);
        foreach ($mustcontain as $expected) {
            $this->assertStringContainsString($expected, $output, "Missing '{$expected}'.");
        }
        foreach ($mustnotcontain as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $output, "Forbidden '{$forbidden}' present.");
        }
    }

    /**
     * Data provider for the MathJax environment escaper tests.
     *
     * @return array<string, array{input: string, mustcontain: string[], mustnotcontain: string[]}>
     */
    public static function escape_mathjax_environments_provider(): array {
        return [
            'begin_document_outside_pre' => [
                'input' => '<p>\documentclass{article}\begin{document}Hello World\end{document}</p>',
                'mustcontain' => [
                    '<span class="mathjax_ignore">\begin{document}</span>',
                    '<span class="mathjax_ignore">\end{document}</span>',
                    '\documentclass{article}',
                ],
                'mustnotcontain' => [],
            ],
            'begin_equation_outside_pre' => [
                'input' => '<p>\begin{equation}x^2\end{equation}</p>',
                'mustcontain' => [
                    '<span class="mathjax_ignore">\begin{equation}</span>',
                    '<span class="mathjax_ignore">\end{equation}</span>',
                ],
                'mustnotcontain' => [],
            ],
            'begin_inside_pre_not_modified' => [
                'input' => '<pre><code>\begin{document}Hello World\end{document}</code></pre>',
                'mustcontain' => ['\begin{document}Hello World\end{document}'],
                'mustnotcontain' => ['mathjax_ignore'],
            ],
            'mixed_pre_and_non_pre' => [
                'input' => '<p>\begin{document}</p><pre><code>\begin{equation}</code></pre><p>\end{document}</p>',
                'mustcontain' => [
                    '<span class="mathjax_ignore">\begin{document}</span>',
                    '<pre><code>\begin{equation}</code></pre>',
                    '<span class="mathjax_ignore">\end{document}</span>',
                ],
                'mustnotcontain' => [],
            ],
            'no_begin_end_patterns' => [
                'input' => '<p>Normal text without LaTeX</p>',
                'mustcontain' => ['<p>Normal text without LaTeX</p>'],
                'mustnotcontain' => ['mathjax_ignore'],
            ],
            'dollar_math_not_affected' => [
                'input' => '<p>$$x^2 + y^2 = z^2$$</p>',
                'mustcontain' => ['$$x^2 + y^2 = z^2$$'],
                'mustnotcontain' => ['mathjax_ignore'],
            ],
            'documentclass_not_affected' => [
                'input' => '<p>\documentclass{article}</p>',
                'mustcontain' => ['\documentclass{article}'],
                'mustnotcontain' => ['mathjax_ignore'],
            ],
            'multiple_environments_outside_pre' => [
                'input' => '<p>\begin{align}x = 1\end{align} and \begin{itemize}\end{itemize}</p>',
                'mustcontain' => [
                    '<span class="mathjax_ignore">\begin{align}</span>',
                    '<span class="mathjax_ignore">\end{align}</span>',
                    '<span class="mathjax_ignore">\begin{itemize}</span>',
                    '<span class="mathjax_ignore">\end{itemize}</span>',
                ],
                'mustnotcontain' => [],
            ],
            'empty_input' => [
                'input' => '',
                'mustcontain' => [],
                'mustnotcontain' => ['mathjax_ignore'],
            ],
        ];
    }

    // -----------------------------------------------------------------
    // Section 8: Regression suite (MBS-10767 follow-up).

    /**
     * Each fixture here corresponds to a specific previously-observed regression in
     * the AI Manager rendering pipeline.
     *
     * @covers ::format_output
     * @covers ::format_ai_markdown_output
     * @dataProvider regression_mbs10767_provider
     * @param string $input The input string.
     * @param string[] $mustcontain Substrings that must appear.
     * @param string[] $mustnotcontain Substrings that must not appear.
     */
    public function test_regression_mbs10767(
        string $input,
        array $mustcontain,
        array $mustnotcontain
    ): void {
        $purpose = new base_purpose();
        $output = $purpose->format_output($input);
        foreach ($mustcontain as $expected) {
            $this->assertStringContainsString(
                $expected,
                $output,
                "MBS-10767 regression: expected '{$expected}'. Got:\n{$output}"
            );
        }
        foreach ($mustnotcontain as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $output,
                "MBS-10767 regression: forbidden '{$forbidden}' present. Got:\n{$output}"
            );
        }
    }

    /**
     * Data provider for MBS-10767 regression tests.
     *
     * @return array<string, array{input: string, mustcontain: string[], mustnotcontain: string[]}>
     */
    public static function regression_mbs10767_provider(): array {
        $fence = self::fence();
        return [
            'language_class_survives_format_text' => [
                'input' => "Demo:\n\n{$fence}python\nprint(\"hello\")\n{$fence}",
                'mustcontain' => ['<code class="python"'],
                'mustnotcontain' => [],
            ],
            'language_class_survives_for_javascript' => [
                'input' => "Demo:\n\n{$fence}javascript\nconsole.log('x');\n{$fence}",
                'mustcontain' => ['<code class="javascript"'],
                'mustnotcontain' => [],
            ],
            'language_class_survives_for_html' => [
                'input' => "Demo:\n\n{$fence}html\n<div>x</div>\n{$fence}",
                'mustcontain' => ['<code class="html"'],
                'mustnotcontain' => [],
            ],
            'hash_include_does_not_become_heading' => [
                'input' => "C demo:\n{$fence}c\n#include <stdio.h>\nint main(void){return 0;}\n{$fence}",
                'mustcontain' => ['<pre>', '#include &lt;stdio.h&gt;'],
                'mustnotcontain' => ['<h1>', '<h2>'],
            ],
            'entities_inside_code_block_are_not_redecoded' => [
                'input' => "Demo:\n\n{$fence}html\n<div class=\"x\">y</div>\n{$fence}",
                'mustcontain' => ['<pre>', '&lt;div class=', '&lt;/div&gt;'],
                'mustnotcontain' => ['<div class="x">y</div>'],
            ],
            'emphasis_text_is_not_mistaken_for_list_item' => [
                'input' => "Title.\n*Note: this is italic, not a list item.*",
                'mustcontain' => ['<em>Note: this is italic, not a list item.</em>'],
                'mustnotcontain' => ['<ul>', '<li>Note'],
            ],
            'mathjax_placeholder_never_leaks_to_output' => [
                'input' => 'Formel: \\( a^2 + b^2 = c^2 \\) und \\[ E = mc^2 \\] und $$x=y$$.',
                'mustcontain' => ['\\(', '\\[', '$$'],
                'mustnotcontain' => ['AIMATHJAXPLACEHOLDER', "\x00PLACEHOLDER"],
            ],
            'raw_paragraph_html_from_llm_is_escaped_not_rendered' => [
                'input' => 'Hello <p>world</p>!',
                'mustcontain' => ['&lt;p&gt;world&lt;/p&gt;'],
                'mustnotcontain' => ['Hello <p>world</p>!'],
            ],
            'blockquote_still_renders' => [
                'input' => "Title.\n> Quote line one.\n> Quote line two.",
                'mustcontain' => ['<blockquote>', 'Quote line one.'],
                'mustnotcontain' => ['&gt; Quote line one.'],
            ],
            'tight_list_does_not_break_into_multiple_uls' => [
                'input' => "Items:\n- One\n- Two\n- Three",
                'mustcontain' => ['<ul>', '<li>', 'One', 'Two', 'Three'],
                'mustnotcontain' => ["</ul>\n<ul>"],
            ],
            'list_item_with_python_fence_keeps_language_class' => [
                'input' => "- Python:\n  {$fence}python\n  print(\"x\")\n  {$fence}",
                'mustcontain' => ['<ul>', '<li>', '<pre>', '<code class="python"', 'print("x")'],
                'mustnotcontain' => [],
            ],
            'llm_emitted_pre_code_html_is_pipelined_correctly' => [
                'input' => 'Demo: <pre><code class="language-javascript">console.log("x");</code></pre>',
                'mustcontain' => ['<pre>', '<code class="javascript"', 'console.log("x")'],
                'mustnotcontain' => [],
            ],
            'simulation_html_outside_codeblock_is_escaped_as_text' => [
                'input' => "Here is a draggable arrow simulation:\n"
                    . "<!DOCTYPE html>\n<html><body><svg><line x1=\"0\"></line></svg></body></html>",
                'mustcontain' => ['&lt;!DOCTYPE', '&lt;svg', '&lt;line'],
                'mustnotcontain' => ['<svg>', '<line ', '<!DOCTYPE'],
            ],
            'nested_blockquotes_render_correctly' => [
                'input' => "> Level 1\n>> Level 2\n>>> Level 3",
                'mustcontain' => ['<blockquote>', 'Level 1', 'Level 2', 'Level 3'],
                'mustnotcontain' => [],
            ],
            'blockquote_with_html_tag_is_escaped' => [
                'input' => "> Use <div> for layout\n>> And <span> for inline",
                'mustcontain' => ['<blockquote>', '&lt;div&gt;', '&lt;span&gt;'],
                'mustnotcontain' => ['<div>', '<span>'],
            ],
        ];
    }

    // -----------------------------------------------------------------
    // Section 9: End-to-end realistic scenarios derived from production tickets.

    /**
     * Realistic scenario: course description with three Hello-World snippets,
     * the very output that triggered MBS-10767 in production.
     *
     * @covers ::format_output
     * @covers ::format_ai_markdown_output
     */
    public function test_realistic_course_description_with_three_codeblocks(): void {
        $fence = self::fence();
        $input = "## Informatik-Kurs\n\n"
            . "In diesem Kurs lernen Sie die Grundlagen.\n\n"
            . "**Hello-World-Beispiele**:\n\n"
            . "Python:\n\n{$fence}python\nprint(\"Hello, World!\")\n{$fence}\n\n"
            . "JavaScript:\n\n{$fence}javascript\nconsole.log(\"Hello, World!\");\n{$fence}\n\n"
            . "C:\n\n{$fence}c\n#include <stdio.h>\nint main(void){printf(\"Hello, World!\\n\");return 0;}\n{$fence}\n";

        $purpose = new base_purpose();
        $output = $purpose->format_output($input);

        $this->assertStringContainsString('<h2>Informatik-Kurs</h2>', $output, 'Heading must render correctly.');
        $this->assertStringContainsString('<strong>Hello-World-Beispiele</strong>', $output, 'Bold must render.');
        $this->assertStringContainsString('<code class="python"', $output, 'Python language class must survive.');
        $this->assertStringContainsString('<code class="javascript"', $output, 'JS language class must survive.');
        $this->assertStringContainsString('<code class="c"', $output, 'C language class must survive.');
        $this->assertStringContainsString('#include &lt;stdio.h&gt;', $output, '#include must stay as encoded text.');
        $this->assertStringNotContainsString('<h1>#include', $output, '#include must NOT become an h1 heading.');
        $this->assertStringNotContainsString('AIMATHJAXPLACEHOLDER', $output, 'No MathJax placeholder leakage.');
    }

    /**
     * Realistic scenario: LLM returns a draggable arrow as raw HTML (the original
     * MBS-10767 prompt).
     *
     * @covers ::format_output
     * @covers ::format_ai_markdown_output
     */
    public function test_realistic_raw_html_simulation_outside_codeblock(): void {
        $input = "Hier ist eine komplette HTML-Datei mit Pfeil:\n\n"
            . "<!DOCTYPE html>\n<html lang=\"de\"><body><svg><line x1=\"0\" y1=\"0\"/></svg></body></html>";

        $purpose = new base_purpose();
        $output = $purpose->format_output($input);

        $this->assertStringContainsString(
            'Hier ist eine komplette HTML-Datei mit Pfeil',
            $output,
            'Intro text must remain visible.'
        );
        $this->assertStringContainsString('&lt;!DOCTYPE', $output, 'DOCTYPE must be HTML-escaped.');
        $this->assertStringContainsString('&lt;svg', $output, 'svg tag must be HTML-escaped.');
        $this->assertStringContainsString('&lt;line', $output, 'line tag must be HTML-escaped.');
        $this->assertStringNotContainsString('<svg>', $output, 'No live svg tag may leak through.');
        $this->assertStringNotContainsString('<line ', $output, 'No live line tag may leak through.');
    }

    /**
     * Realistic scenario: a famous quote, asked to be formatted as a blockquote.
     *
     * @covers ::format_output
     * @covers ::format_ai_markdown_output
     */
    public function test_realistic_blockquote_einstein(): void {
        $input = "> Phantasie ist wichtiger als Wissen.\n> – Albert Einstein";
        $purpose = new base_purpose();
        $output = $purpose->format_output($input);

        $this->assertStringContainsString('<blockquote>', $output, 'Blockquote must render.');
        $this->assertStringContainsString('Phantasie ist wichtiger als Wissen.', $output, 'Quote text must be present.');
        $this->assertStringNotContainsString('&gt; Phantasie', $output, 'Markdown quote markers must not survive.');
    }

    /**
     * Realistic scenario: MathJax binomial formulas in a chat reply.
     *
     * @covers ::format_output
     * @covers ::format_ai_markdown_output
     */
    public function test_realistic_mathjax_binomial_formulas(): void {
        $input = "Die binomischen Formeln:\n\n"
            . "- Erste Formel: \\( (a+b)^2 = a^2 + 2ab + b^2 \\)\n"
            . "- Zweite Formel: \\( (a-b)^2 = a^2 - 2ab + b^2 \\)\n"
            . "- Dritte Formel: \\( (a+b)(a-b) = a^2 - b^2 \\)";

        $purpose = new base_purpose();
        $output = $purpose->format_output($input);

        $this->assertStringContainsString('<ul>', $output, 'List must render.');
        $this->assertStringContainsString('\\( (a+b)^2 = a^2 + 2ab + b^2 \\)', $output, 'First formula must survive.');
        $this->assertStringContainsString('\\( (a-b)^2 = a^2 - 2ab + b^2 \\)', $output, 'Second formula must survive.');
        $this->assertStringContainsString('\\( (a+b)(a-b) = a^2 - b^2 \\)', $output, 'Third formula must survive.');
        $this->assertStringNotContainsString('AIMATHJAXPLACEHOLDER', $output, 'No placeholder leakage.');
    }

    /**
     * Realistic scenario: dangerous payload outside code blocks must still be
     * sanitised while inline math survives.
     *
     * @covers ::format_output
     * @covers ::format_ai_markdown_output
     */
    public function test_realistic_xss_with_math(): void {
        $input = 'Feedback: <script>alert("xss")</script> und \\( x^2 \\)';
        $purpose = new base_purpose();
        $output = $purpose->format_output($input);

        $this->assertStringNotContainsString('<script>', $output, 'No live script tag may survive.');
        $this->assertStringNotContainsString('<script ', $output, 'No live script tag may survive.');
        $this->assertStringContainsString('&lt;script&gt;', $output, 'Script tag must be HTML-escaped.');
        $this->assertStringContainsString('&lt;/script&gt;', $output, 'Closing script tag must be HTML-escaped.');
        $this->assertStringContainsString('\\( x^2 \\)', $output, 'Inline math must survive unchanged.');
    }

    /**
     * Realistic scenario: plain text without any Markdown or HTML must be preserved.
     *
     * @covers ::format_output
     * @covers ::format_ai_markdown_output
     */
    public function test_realistic_plain_text_paragraph(): void {
        $input = 'Du hast die richtigen Grundideen erfasst — sehr gut!';
        $purpose = new base_purpose();
        $output = $purpose->format_output($input);
        $this->assertStringContainsString('sehr gut', $output, 'Plain text must be preserved.');
    }

    // -----------------------------------------------------------------
    // Section 10: MBS-10767 follow-up — bare HTML documents and code-block whitespace.

    /**
     * Production regression: the LLM answered the prompt "generiere mir ein längeres
     * html-/js-beispiel" by emitting a complete <!doctype html> document as raw text,
     * NOT inside a code fence. Before the fix the pipeline let MarkdownExtra pass the
     * block-level tags through verbatim while htmlspecialchars-ing everything else,
     * producing the half-live / half-entity-encoded soup visible in the screenshot
     * "ganzes HTML-File falsches Parsing.png".
     *
     * Contract pinned down: a bare HTML document MUST be rendered as ONE clean
     * ```html fenced code block; no raw <!doctype/<html/<style/<script tag may
     * leak through as live HTML; no partial entity encoding may appear in the
     * visible content.
     *
     * @covers ::format_output
     * @covers ::format_ai_markdown_output
     */
    public function test_bare_html_document_is_wrapped_into_one_clean_code_block(): void {
        $intro = "Hier ist eine einzelne HTML-Datei, die eine längere HTML/JS-Demo zeigt. "
            . "Speichere den Inhalt als .html und öffne ihn im Browser.\n\n";
        $document = "<!doctype html>\n"
            . "<html lang=\"de\">\n"
            . "<head>\n"
            . "  <meta charset=\"utf-8\">\n"
            . "  <title>Demo</title>\n"
            . "  <style>body{font:14px/1.4 system-ui;color:#0b1b2b}</style>\n"
            . "</head>\n"
            . "<body data-theme=\"dark\">\n"
            . "  <header><h1>Demo</h1></header>\n"
            . "  <main>\n"
            . "    <textarea id=\"demo-src\" style=\"display:none;\">\n"
            . "      <p>el.innerHTML = '<span class=\"x\">y</span>';</p>\n"
            . "    </textarea>\n"
            . "  </main>\n"
            . "  <script>\n"
            . "    document.getElementById('demo-src').textContent = 'hello';\n"
            . "  </script>\n"
            . "</body>\n"
            . "</html>";

        $purpose = new base_purpose();
        $output = $purpose->format_output($intro . $document);

        // Intro prose must remain visible plain text.
        $this->assertStringContainsString(
            'Hier ist eine einzelne HTML-Datei',
            $output,
            'Intro prose must survive.'
        );

        // The document must be rendered inside ONE pre/code block tagged as html.
        $this->assertStringContainsString('<pre>', $output, 'Document must be inside a <pre> block.');
        $this->assertStringContainsString(
            '<code class="html"',
            $output,
            'Code block must carry the html language class.'
        );
        $this->assertSame(
            1,
            substr_count($output, '<pre>'),
            'There must be exactly one <pre> block, never a fragmented sequence of multiple blocks.'
        );
        $this->assertSame(
            1,
            substr_count($output, '<code class="html"'),
            'There must be exactly one html-tagged code element.'
        );

        // All document tokens must appear as entity-encoded TEXT, never as live tags.
        $this->assertStringContainsString('&lt;!doctype', $output, '<!doctype must be entity-encoded.');
        $this->assertStringContainsString('&lt;html lang=', $output, '<html> must be entity-encoded.');
        $this->assertStringContainsString('&lt;style&gt;', $output, '<style> must be entity-encoded.');
        $this->assertStringContainsString('&lt;script&gt;', $output, '<script> must be entity-encoded.');
        $this->assertStringContainsString('&lt;textarea', $output, '<textarea> must be entity-encoded.');

        // No live document-structuring tag may leak through.
        $this->assertStringNotContainsString('<!doctype html>', $output, 'Live <!doctype> must not survive.');
        $this->assertStringNotContainsString('<html lang=', $output, 'Live <html> must not survive.');
        $this->assertStringNotContainsString('<style>body{', $output, 'Live <style> must not survive.');
        $this->assertStringNotContainsString('<script>', $output, 'Live <script> must not survive.');
        $this->assertStringNotContainsString('<textarea', $output, 'Live <textarea> must not survive.');
    }

    /**
     * MarkdownExtra has a documented bug where it surrounds the <code> contents of
     * a fenced code block with leading/trailing blank lines that the user did not
     * write. Stage 5b restores Philipp's earlier guard against that behaviour.
     *
     * Contract pinned down: the first character inside <pre><code…> is the first
     * character of the user's code; the last character is the last character of
     * the user's code. No spurious leading/trailing newline pair may appear.
     *
     * @covers ::format_output
     * @covers ::format_ai_markdown_output
     */
    public function test_no_spurious_blank_lines_inside_rendered_code_block(): void {
        $fence = self::fence();
        $input = "Demo:\n\n{$fence}python\nprint(\"hello\")\nprint(\"world\")\n{$fence}";
        $purpose = new base_purpose();
        $output = $purpose->format_output($input);

        $this->assertStringContainsString('<pre>', $output, 'Code block must render.');
        $this->assertStringContainsString('print("hello")', $output, 'First line must survive.');
        $this->assertStringContainsString('print("world")', $output, 'Last line must survive.');

        // Extract the rendered code body and assert it has no leading/trailing blank lines.
        $matched = preg_match('#<code(?:\s+class="[^"]*")?>([\s\S]*?)</code>#', $output, $m);
        $this->assertSame(1, $matched, "Expected exactly one <code> element in output. Got:\n{$output}");
        $body = $m[1];
        $this->assertSame(
            ltrim($body, "\n"),
            $body,
            'Rendered <code> body must not start with a newline (MarkdownExtra bug guard).'
        );
        $this->assertSame(
            rtrim($body, "\n"),
            $body,
            'Rendered <code> body must not end with a newline (MarkdownExtra bug guard).'
        );
    }

    /**
     * Guard test: a bare HTML *fragment* (no DOCTYPE) must continue to take the
     * prose-escaping path — it must NOT be wrapped into a synthetic code block.
     * This pins down Stage 2b's narrow detection criterion so future maintainers
     * cannot accidentally make the wrapper greedier.
     *
     * @covers ::format_output
     * @covers ::format_ai_markdown_output
     */
    public function test_bare_html_fragment_without_doctype_is_still_entity_escaped(): void {
        $input = "Some intro.\n\n<div class=\"x\">y</div>\n\n<span>z</span>";
        $purpose = new base_purpose();
        $output = $purpose->format_output($input);

        $this->assertStringContainsString('Some intro.', $output, 'Intro must survive.');
        $this->assertStringContainsString('&lt;div class=', $output, 'Fragment must be entity-encoded.');
        $this->assertStringContainsString('&lt;span&gt;', $output, 'Fragment must be entity-encoded.');
        $this->assertStringNotContainsString('<div class="x">', $output, 'No live div must leak.');
        $this->assertStringNotContainsString('<span>z</span>', $output, 'No live span must leak.');
        // And it must NOT have been wrapped into a <pre>/<code> block.
        $this->assertStringNotContainsString(
            '<pre>',
            $output,
            'Plain HTML fragments must not be wrapped into a code block.'
        );
    }
}

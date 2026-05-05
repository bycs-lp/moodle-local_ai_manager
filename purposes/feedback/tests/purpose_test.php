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

namespace aipurpose_feedback;

use advanced_testcase;

/**
 * Unit tests for the feedback purpose format_output with MathJax content.
 *
 * Tests are based on real AI response data from production where MathJax
 * rendering was broken due to backslash stripping during text processing.
 * See MBS-10777.
 *
 * @package    aipurpose_feedback
 * @copyright  2026 ISB Bayern
 * @author     ISB Bayern
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \aipurpose_feedback\purpose
 */
final class purpose_test extends advanced_testcase {

    /**
     * Data provider with real-world MathJax formulas from AI feedback responses.
     *
     * @return array test cases
     */
    public static function mathjax_preservation_provider(): array {
        return [
            'inline_frac_delta' => [
                'input' => 'Die Definition: \( v = \frac{\Delta x}{\Delta t} \) ist korrekt.',
                'mustcontain' => ['\( v = \frac{\Delta x}{\Delta t} \)'],
            ],
            'inline_vec' => [
                'input' => 'Vektor: \( \vec{v} = \frac{\Delta \vec{x}}{\Delta t} \)',
                'mustcontain' => ['\vec{v}', '\frac', '\Delta \vec{x}'],
            ],
            'display_dollar_text_command' => [
                'input' => 'Umrechnung: $$1\ \text{m/s} = 3{,}6\ \text{km/h}$$',
                'mustcontain' => ['$$1\ \text{m/s} = 3{,}6\ \text{km/h}$$'],
            ],
            'display_bracket_cdot' => [
                'input' => 'Kraft: \[ \vec{F} = m \cdot \vec{a} \]',
                'mustcontain' => ['\vec{F}', '\cdot', '\vec{a}', '\[', '\]'],
            ],
            'inline_sqrt' => [
                'input' => 'Betrag: \( |\vec{v}| = \sqrt{v_x^2 + v_y^2} \)',
                'mustcontain' => ['\sqrt{v_x^2 + v_y^2}', '\vec{v}'],
            ],
            'inline_rightarrow_quad' => [
                'input' => '\( \vec{F} = m\vec{a} \quad\Rightarrow\quad \Delta\vec{v} = \frac{\vec{F}}{m}\,\Delta t \)',
                'mustcontain' => ['\Rightarrow', '\quad', '\frac{\vec{F}}{m}'],
            ],
            'multiple_inline_blocks' => [
                'input' => 'Gegeben \( \vec{v}_1 \) und \( \vec{v}_2 \), dann \( \Delta \vec{v} = \vec{v}_2 - \vec{v}_1 \).',
                'mustcontain' => ['\( \vec{v}_1 \)', '\( \vec{v}_2 \)', '\Delta \vec{v}'],
            ],
            'mixed_display_dollar_and_inline' => [
                'input' => "Inline: \\( x^2 \\)\n\nDisplay: \$\$y = mx + b\$\$\n\nNoch: \\( z = 5 \\)",
                'mustcontain' => ['\( x^2 \)', '$$y = mx + b$$', '\( z = 5 \)'],
            ],
            'mathrm_command' => [
                'input' => '\( 1\ \mathrm{m/s} = \frac{3600\ \mathrm{m}}{3600\ \mathrm{s}} = 3{,}6\ \mathrm{km/h} \)',
                'mustcontain' => ['\mathrm{m/s}', '\mathrm{km/h}', '\frac'],
            ],
            'acceleration_definition' => [
                'input' => 'Beschleunigung: \( \vec{a} = \frac{\Delta \vec{v}}{\Delta t} \)',
                'mustcontain' => ['\vec{a}', '\frac{\Delta \vec{v}}{\Delta t}'],
            ],
        ];
    }

    /**
     * Test that MathJax/LaTeX content survives the complete format_output pipeline.
     *
     * @dataProvider mathjax_preservation_provider
     * @param string $input The raw AI completion text.
     * @param array $mustcontain LaTeX fragments that must be present verbatim in the output.
     */
    public function test_mathjax_preserved_through_pipeline(string $input, array $mustcontain): void {
        $purpose = new purpose();
        $output = $purpose->format_output($input);

        foreach ($mustcontain as $expected) {
            $this->assertStringContainsString(
                $expected,
                $output,
                "LaTeX fragment '{$expected}' was destroyed by format_output(). Output was: {$output}"
            );
        }
    }

    /**
     * Data provider for Markdown rendering tests alongside MathJax.
     *
     * @return array test cases
     */
    public static function markdown_with_mathjax_provider(): array {
        return [
            'bold_text_with_inline_math' => [
                'input' => 'Die **Skalargeschwindigkeit** ist \( v = \frac{\Delta x}{\Delta t} \) (Einheit m/s).',
                'mustcontain' => ['<strong>Skalargeschwindigkeit</strong>', '\frac', '\Delta'],
            ],
            'heading_with_math' => [
                'input' => "## Konkrete Korrekturen\n\nFormel: \\( \\vec{v} = \\frac{\\Delta\\vec{x}}{\\Delta t} \\)",
                'mustcontain' => ['<h2>', '\vec{v}', '\frac'],
            ],
            'list_with_math' => [
                'input' => "Formeln:\n\n"
                    . "- Geschwindigkeit: \\( v = \\frac{\\Delta x}{\\Delta t} \\)\n"
                    . "- Beschleunigung: \\( a = \\frac{\\Delta v}{\\Delta t} \\)\n"
                    . "- Kraft: \\( F = m \\cdot a \\)\n",
                'mustcontain' => ['<li>', '\frac', '\cdot'],
            ],
        ];
    }

    /**
     * Test that Markdown is converted to HTML while MathJax stays intact.
     *
     * @dataProvider markdown_with_mathjax_provider
     * @param string $input The raw AI text with Markdown + LaTeX.
     * @param array $mustcontain Strings that must be present in the output.
     */
    public function test_markdown_rendered_with_mathjax_intact(string $input, array $mustcontain): void {
        $purpose = new purpose();
        $output = $purpose->format_output($input);

        foreach ($mustcontain as $expected) {
            $this->assertStringContainsString($expected, $output);
        }
    }

    /**
     * Test that dangerous HTML in AI feedback is still sanitized.
     */
    public function test_xss_sanitized_with_math(): void {
        $purpose = new purpose();
        $input = 'Feedback: <script>alert("xss")</script> und \( x^2 \)';
        $output = $purpose->format_output($input);

        $this->assertStringNotContainsString('<script>', $output);
        $this->assertStringNotContainsString('alert(', $output);
        $this->assertStringContainsString('\( x^2 \)', $output);
    }

    /**
     * Test that plain text without math is not broken.
     */
    public function test_plain_text_unchanged(): void {
        $purpose = new purpose();
        $input = 'Du hast die richtigen Grundideen erfasst — sehr gut!';
        $output = $purpose->format_output($input);

        $this->assertStringContainsString('sehr gut', $output);
    }

    /**
     * Test with a realistic AI feedback excerpt derived from production data (MBS-10777).
     */
    public function test_real_world_production_feedback(): void {
        $purpose = new purpose();
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
        $output = $purpose->format_output($input);

        // Markdown structure must be converted.
        $this->assertStringContainsString('<h2>', $output);
        $this->assertStringContainsString('<ol>', $output);
        $this->assertStringContainsString('<li>', $output);

        // All LaTeX must survive verbatim.
        $this->assertStringContainsString('\frac{\Delta x}{\Delta t}', $output);
        $this->assertStringContainsString('\vec{v}', $output);
        $this->assertStringContainsString('\text{m/s}', $output);
        $this->assertStringContainsString('\text{km/h}', $output);
        $this->assertStringContainsString('\vec{v}_2 - \vec{v}_1', $output);
        $this->assertStringContainsString('\cdot', $output);

        // Delimiters must be intact.
        $this->assertStringContainsString('\(', $output);
        $this->assertStringContainsString('\)', $output);
        $this->assertStringContainsString('$$', $output);
    }
}

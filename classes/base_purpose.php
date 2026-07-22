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
use local_ai_manager\local\userinfo;
use Michelf\MarkdownExtra;

/**
 * Base class for purpose subplugins.
 *
 * @package    local_ai_manager
 * @copyright  ISB Bayern, 2024
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class base_purpose {
    /** @var string Constant for defining that a purpose option is an array */
    const PARAM_ARRAY = 'array';

    /**
     * Returns a localized description of the purpose.
     *
     * @return string the localized string describing the purpose and what it's supposed to be used for
     */
    public function get_description(): string {
        return get_string('purposedescription', 'aipurpose_' . $this->get_plugin_name());
    }

    /**
     * Helper function that returns an array with purposes.
     *
     * The returned array has all installed purposes as keys and an empty array as value so that single purpose keys can be
     * overridden by the purpose subplugins to define which purposes they want to support.
     *
     * The array has the form:
     * [
     *     'chat' => [],
     *     'feedback' => [],
     *     ... all other installed purposes ...
     * ]
     *
     * @return array the array with names of all installed purposes as keys and empty arrays as values
     */
    public static function get_installed_purposes_array(): array {
        $installedpurposes = array_keys(core_plugin_manager::instance()->get_installed_plugins('aipurpose'));
        $purposearray = [];
        foreach ($installedpurposes as $installedpurpose) {
            $purposearray[$installedpurpose] = [];
        }
        return $purposearray;
    }

    /**
     * Getter for the request options.
     *
     * @param array $options the current options which can be filtered/manipulated etc.
     * @return array the eventually manipulated options array
     */
    final public function get_request_options(array $options): array {
        $newoptions = [];
        if (!empty($options['itemid'])) {
            $newoptions['itemid'] = $options['itemid'];
        }
        if (!empty($options['forcenewitemid'])) {
            $newoptions['forcenewitemid'] = $options['forcenewitemid'];
        }
        return $newoptions + $this->get_additional_request_options($options);
    }

    /**
     * Function that can be used by subclasses to manipulate the options being sent in a request.
     *
     * Subclasses can override this function and manipulate the options being sent in a request to the
     * needs of the specific purpose. The default is to just use all options. The options are being sanitized before
     * by using {@see self::get_available_purpose_options}.
     *
     * @param array $options the options being sent in the request
     * @return array the manipulated options
     */
    public function get_additional_request_options(array $options): array {
        return $options;
    }

    /**
     * Returns all enabled purpose subplugins.
     *
     * @return array array of purpose subplugin names
     */
    public static function get_all_purposes(): array {
        return core_plugin_manager::instance()->get_enabled_plugins('aipurpose');
    }

    /**
     * Returns the name of the config key for storing the configured tool for a given purpose.
     *
     * @param string $purpose the purpose name
     * @param int $role the local_ai_manager internal role to retrieve the config key for
     * @return string the config key for storing the config setting for accessing the config via the config manager
     */
    public static function get_purpose_tool_config_key(string $purpose, int $role): string {
        // Currently, userinfo::ROLE_EXTENDED and userinfo::ROLE_UNLIMITED are handled equally.
        if ($role === userinfo::ROLE_UNLIMITED) {
            $role = userinfo::ROLE_EXTENDED;
        }
        return 'purpose_' . $purpose . '_tool_' . userinfo::get_role_as_string($role);
    }

    /**
     * Helper function for determining the plugin name based on this object.
     *
     * @return string the plugin name
     */
    final public function get_plugin_name(): string {
        return preg_replace('/^aipurpose_(.*)\\\\.*/', '$1', get_class($this));
    }

    /**
     * Get the options defined by this purpose.
     *
     * @return array associative array defining the options
     * @throws \coding_exception in case that a subclass tries to define an option which is already being defined in the
     *  parent class
     */
    final public function get_available_purpose_options(): array {
        $options = [];
        $options['itemid'] = PARAM_INT;
        $options['forcenewitemid'] = PARAM_BOOL;
        $additionalpurposeoptions = $this->get_additional_purpose_options();
        foreach (array_keys($additionalpurposeoptions) as $purposeoption) {
            if (in_array($purposeoption, $options)) {
                throw new \coding_exception('You must not define options in the purpose subclass which are being used in the '
                . 'base class.');
            }
        }
        return $options + $additionalpurposeoptions;
    }

    /**
     * Function to define purpose options.
     *
     * Should be overwritten of subclasses if they want to add options.
     *
     * @return array the options array
     */
    public function get_additional_purpose_options(): array {
        return [];
    }

    /**
     * Most AI tools will return Markdown code, so we use this as default.
     *
     * Can be overwritten by purposes which return special content, for example single strings which should not be wrapped
     * or cleaned.
     *
     * @param string $output the output/result from the API of the AI tool
     * @return string the formatted output
     */
    public function format_output(string $output): string {
        return $this->format_ai_markdown_output($output, ['filter' => false, 'newlines' => false]);
    }

    /**
     * Converts markdown text to sanitized HTML.
     *
     * First converts markdown to HTML using Moodle's core markdown_to_html() function,
     * then sanitizes the result with format_text() to prevent XSS from raw HTML
     * that the LLM might return.
     *
     * @param string $markdown The markdown text to convert.
     * @param array $options Additional options to pass to format_text().
     * @return string The sanitized HTML output.
     */
    public function format_ai_markdown_output(string $markdown, array $options = []): string {
        // Mask math/LaTeX segments behind placeholders before running MarkdownExtra, which would otherwise
        // consume the backslash escapes and destroy MathJax delimiters and matrix row separators. Store the
        // segments unchanged; they are restored (html-escaped) after the conversion.
        $mathsegments = [];
        $markdown = self::mask_math_segments($markdown, fn($segment) => $segment, $mathsegments);

        // Ensure blank lines around fenced code blocks inside list items.
        // PHP Markdown Extra only correctly parses fenced code blocks (including language identifiers)
        // inside "loose" list items (separated by blank lines). Without blank lines,
        // fenced code blocks are either rendered without <pre> or completely broken.
        // We normalize by:
        // 1. Adding a blank line before every list item marker (* or -) to make all list items "loose".
        $markdown = preg_replace('/(?<!\n)\n(\s*[\*\-]\s)/', "\n\n$1", $markdown);
        // 2. Adding a blank line before fenced code block openings with language identifiers
        // (e.g. html) that directly follow a non-empty line. Code blocks without language
        // identifiers work correctly without this fix.
        $markdown = preg_replace('/(?<!\n)\n(\s*\x60{3}\w)/', "\n\n$1", $markdown);

        // Configure MarkdownExtra so fenced code blocks carry the language class on the <pre> element with a
        // "language-" prefix, which is what Prism.js (filter_codehighlighter) expects. HTML inside code blocks
        // is escaped either way.
        $markdownparser = new MarkdownExtra();
        $markdownparser->code_class_prefix = 'language-';
        $markdownparser->code_attr_on_pre = true;
        $html = $markdownparser->transform($markdown);
        // Fenced blocks without a language identifier get no class at all and would look different from
        // highlighted ones, so give them Prism's neutral styling.
        $html = str_replace('<pre><code>', '<pre class="language-none"><code>', $html);

        // Restore masked math segments, outermost first: an outer \begin{}...\end{} may contain inner
        // \(...\) placeholders, which only reappear once the outer segment is reinserted.
        // ENT_NOQUOTES matches MarkdownExtra's fenced code escaping; format_text() below still sanitizes.
        foreach (array_reverse($mathsegments, true) as $placeholder => $mathsegment) {
            $html = str_replace($placeholder, htmlspecialchars($mathsegment, ENT_NOQUOTES), $html);
        }
        // Apply Moodle output function for both sanitizing and other Moodle specific formatting.
        // Previously converted markdown-generated structure is being preserved.
        // This prevents XSS from raw HTML that the LLM might return.
        return format_text($html, FORMAT_HTML, $options);
    }

    /**
     * Masks math/LaTeX segments ($$ ... $$, \[ ... \], \( ... \), \begin{env} ... \end{env}) behind unique
     * placeholders.
     *
     * Used to protect LaTeX backslashes from processing steps that would otherwise destroy them: the
     * MarkdownExtra conversion in {@see self::format_ai_markdown_output()} and the JSON escape repair in the
     * agent purpose. The caller decides via $store how the matched segment is stored (verbatim, or with
     * doubled backslashes) and restores the placeholders itself.
     *
     * @param string $text the text to mask
     * @param callable $store maps a matched segment (string) to the value stored under its placeholder
     * @param array $segments filled with placeholder => stored-value pairs, by reference
     * @return string the text with math segments replaced by placeholders
     */
    protected static function mask_math_segments(string $text, callable $store, array &$segments): string {
        // Random, hard-to-spoof placeholder prefix (uniqid without dots) so the LLM cannot forge placeholders.
        $prefix = 'AIMATHMASK' . str_replace('.', '', uniqid('', true));
        $index = 0;
        $mask = function ($matches) use (&$segments, &$index, $prefix, $store) {
            // The non-digit terminator after the index prevents any placeholder from being a prefix
            // of another one or of a placeholder followed by a literal digit in the text.
            $placeholder = $prefix . $index++ . 'X';
            $segments[$placeholder] = $store($matches[0]);
            return $placeholder;
        };
        // Mask the four supported math delimiter forms: display math, and the two inline LaTeX delimiter
        // pairs, plus bare begin/end environments.
        $text = preg_replace_callback('/\$\$.+?\$\$/s', $mask, $text);
        $text = preg_replace_callback('/\\\\\[.+?\\\\\]/s', $mask, $text);
        $text = preg_replace_callback('/\\\\\(.+?\\\\\)/s', $mask, $text);
        $text = preg_replace_callback('/\\\\begin\{([a-zA-Z*]+)\}.*?\\\\end\{\1\}/s', $mask, $text);
        return $text;
    }

    /**
     * Returns the default formatting prompt.
     *
     * @return string The default formatting prompt as string.
     */
    public static function get_default_formatting_prompt(): string {
        return <<<EOF
When writing program code or markup (HTML, CSS, JavaScript, Python, etc.),
ALWAYS wrap it in fenced code blocks with the appropriate language identifier.
For short code fragments inside a sentence, use inline code with single backticks.

Use Markdown syntax for text formatting (headings, bold, italic, lists).
Do not use raw HTML tags for formatting purposes.

Wrap ALL mathematical formulas and expressions in MathJax delimiters:
\( ... \) for inline math and $$ ... $$ for display math. This also applies
to formulas inside running text and derivation steps.
Never put mathematical formulas in fenced code blocks unless the user
explicitly asks for (La)TeX source code.
EOF;
    }

    /**
     * Formats the given prompt text based on the provided sanitized options.
     *
     * @param string $prompttext The prompt text to be formatted.
     * @param request_options $requestoptions The request options objects.
     *
     * @return string The formatted prompt text as string.
     */
    public function format_prompt_text(string $prompttext, request_options $requestoptions): string {
        return $prompttext;
    }
}

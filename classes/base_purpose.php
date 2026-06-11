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

use coding_exception;
use core_plugin_manager;
use local_ai_manager\local\userinfo;

/**
 * Base class for purpose subplugins.
 *
 * @package    local_ai_manager
 * @copyright  ISB Bayern, 2024
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class base_purpose {
    /** @var string Constant for defining that a purpose option is an array. */
    const PARAM_ARRAY = 'array';

    /** @var string Prefix used for opaque MathJax placeholders during the pipeline. */
    private const MATHJAX_PLACEHOLDER_PREFIX = 'AIMATHJAXPLACEHOLDER';

    /** @var string Suffix used for opaque MathJax placeholders during the pipeline. */
    private const MATHJAX_PLACEHOLDER_SUFFIX = 'END';

    /**
     * Returns a localized description of the purpose.
     *
     * @return string the localized string describing the purpose and what it's supposed to be used for
     * @throws coding_exception when no purposedescription lang string exists for this purpose.
     */
    public function get_description(): string {
        return get_string('purposedescription', 'aipurpose_' . $this->get_plugin_name());
    }

    /**
     * Helper function that returns an array with purposes.
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
     * @return string the config key
     * @throws coding_exception if the role cannot be resolved to a known role string.
     */
    public static function get_purpose_tool_config_key(string $purpose, int $role): string {
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
     * @throws coding_exception in case that a subclass tries to define an option which is already being defined in the
     *  parent class
     */
    final public function get_available_purpose_options(): array {
        $options = [];
        $options['itemid'] = PARAM_INT;
        $options['forcenewitemid'] = PARAM_BOOL;
        $additionalpurposeoptions = $this->get_additional_purpose_options();
        foreach (array_keys($additionalpurposeoptions) as $purposeoption) {
            if (in_array($purposeoption, $options)) {
                throw new coding_exception('You must not define options in the purpose subclass which are being used in the '
                    . 'base class.');
            }
        }
        return $options + $additionalpurposeoptions;
    }

    /**
     * Function to define purpose options.
     *
     * @return array the options array
     */
    public function get_additional_purpose_options(): array {
        return [];
    }

    /**
     * Most AI tools will return Markdown code, so we use this as default.
     *
     * @param string $output the output/result from the API of the AI tool
     * @return string the formatted output
     * @throws coding_exception if format_text() fails to resolve a context, which should not happen in this code path.
     */
    public function format_output(string $output): string {
        return $this->format_ai_markdown_output($output, ['filter' => false, 'newlines' => false]);
    }

    /**
     * Converts LLM-emitted Markdown (and possibly mixed HTML) into safely sanitized HTML.
     *
     * This is a six-stage pipeline. The stages are pure and well-defined, so each one
     * can be unit-tested in isolation. The order and the contract between stages is
     * critical to security AND correctness; do not reorder them without re-reading the
     * docblocks.
     *
     *   Stage 1: Protect MathJax/LaTeX blocks behind opaque placeholders.
     *            Rationale: PHP Markdown Extra interprets the backslash as an escape
     *            character and would mangle inline math, display math, and macros like
     *            "frac". We save them, run the pipeline on placeholder strings, and
     *            restore them in stage 5.
     *
     *   Stage 2: Convert LLM-emitted raw "pre code" HTML blocks into Markdown fenced
     *            code blocks. Some models still emit HTML for code; we want them to
     *            take the same pipeline path as native Markdown fences.
     *
     *   Stage 2b: Wrap any LLM-emitted bare HTML *document* (a buffer containing a
     *             literal "<!doctype …>" declaration and a matching "</html>"
     *             closing tag) into a synthetic ```html fenced code block.
     *             Rationale: MarkdownExtra treats top-level block-level HTML as
     *             pass-through, so without this stage the document's structural
     *             tags would survive verbatim through stage 5 while their inline
     *             attribute strings and text content would go through stage 4's
     *             htmlspecialchars(), producing the half-live / half-entity-encoded
     *             output observed in MBS-10767 (screenshot
     *             "ganzes HTML-File falsches Parsing.png"). Tag-only fragments
     *             (no DOCTYPE) continue to take the prose-escaping path.
     *
     *   Stage 3: Normalize Markdown structure (lists, fenced-code openings) so that
     *            PHP Markdown Extra parses them correctly. The regexes are
     *            intentionally conservative — see {@see self::normalize_markdown_structure()}
     *            for the exact contract.
     *
     *   Stage 4: Neutralize every raw HTML tag that appears OUTSIDE code/blockquote
     *            regions. We extract those regions behind null-byte placeholders,
     *            run htmlspecialchars on what is left, and restore the regions.
     *            After this stage no executable HTML can survive in prose context.
     *
     *   Stage 5: Run markdown_to_html() (uses MarkdownExtra), then restore the
     *            MathJax placeholders saved in stage 1, then wrap stray LaTeX
     *            environment patterns outside "pre" blocks in mathjax_ignore spans.
     *
     *   Stage 5b: Strip MarkdownExtra's spurious leading and trailing newlines
     *             inside <pre><code>…</code></pre> blocks. MarkdownExtra emits
     *             "<pre><code>\n…\n</code></pre>" where the leading and trailing
     *             "\n" render as visible blank lines above and below the code
     *             content. Originally introduced before the MBS-10767 pipeline
     *             refactor, lost during that rewrite, restored here.
     *
     *   Stage 6: Run format_text() with noclean=true so HTMLPurifier does NOT
     *            re-process the already-safe Markdown HTML output. HTMLPurifier
     *            would otherwise:
     *              - strip class="language-*" from generated code tags (breaks
     *                syntax highlight CSS hooks),
     *              - re-decode HTML entities inside pre/code blocks (caused the
     *                heading regression observed in MBS-10767).
     *            Skipping HTMLPurifier is safe because every untrusted token has
     *            already been neutralized in stage 4 OR is inside a MarkdownExtra
     *            code block where entities are emitted as encoded text.
     *
     * @param string $markdown The markdown text to convert.
     * @param array $options Additional options to pass to format_text(). The
     *      noclean flag is always overridden to true by this method; any caller-
     *      supplied value is intentionally ignored for the security reason above.
     * @return string The sanitized HTML output.
     * @throws coding_exception if Moodle's format_text() rejects the input. Should
     *      never occur in this code path because input is always FORMAT_HTML.
     */
    public function format_ai_markdown_output(string $markdown, array $options = []): string {
        // Stage 1: save MathJax blocks behind placeholders so MarkdownExtra cannot mangle them.
        $mathblocks = [];
        $markdown = self::protect_math_blocks($markdown, $mathblocks);

        // Stage 2: turn LLM-emitted raw pre/code HTML into Markdown fenced code blocks.
        $markdown = self::html_code_blocks_to_markdown_fences($markdown);

        // Stage 2b: turn LLM-emitted bare HTML *documents* (DOCTYPE + html element)
        // into Markdown fenced code blocks. Without this step the document tags
        // would survive verbatim through stage 5 because MarkdownExtra treats
        // top-level block-level HTML as pass-through, producing the half-live /
        // half-entity-encoded output observed in MBS-10767 (screenshot
        // "ganzes HTML-File falsches Parsing.png").
        $markdown = self::wrap_bare_html_documents_in_fences($markdown);

        // Stage 3: normalize Markdown structure so PHP Markdown Extra parses lists and fences correctly.
        $markdown = self::normalize_markdown_structure($markdown);

        // Stage 4: neutralize raw HTML outside code/blockquote regions (XSS prevention).
        $markdown = self::neutralize_raw_html_outside_code($markdown);

        // Stage 5: Markdown to HTML, restore MathJax blocks, then guard MathJax environments.
        $html = markdown_to_html($markdown);
        $html = self::restore_math_blocks($html, $mathblocks);
        $html = self::escape_mathjax_environments($html);

        // Stage 5b: strip MarkdownExtra's spurious leading/trailing blank lines
        // inside <pre><code> blocks. MarkdownExtra emits "<pre><code>\n…\n</code></pre>"
        // where the leading and trailing "\n" are visible whitespace lines in the
        // rendered chat. Originally introduced before the MBS-10767 pipeline
        // refactor and re-introduced here after it got lost.
        $html = self::strip_blank_lines_inside_code_blocks($html);

        // Stage 6: final pass through Moodle's format_text() WITHOUT HTMLPurifier.
        //
        // We override noclean unconditionally, even if the caller passed noclean=false,
        // because the security model of this pipeline relies on stage 4 having already
        // escaped every untrusted token. Running HTMLPurifier on top is at best a no-op
        // and at worst actively destructive (it strips class="language-*" from
        // generated code tags and re-decodes entities inside pre/code).
        $options['noclean'] = true;
        return format_text($html, FORMAT_HTML, $options);
    }

    /**
     * Stage 1 of {@see self::format_ai_markdown_output()}.
     *
     * Replaces every MathJax block in the given text with an opaque placeholder so
     * that subsequent Markdown processing cannot interpret the backslashes inside
     * them as escape sequences. Supported delimiters: inline math, display math
     * with square brackets, and display math with double dollar signs.
     *
     * Visibility is protected (not private) so individual stages can be exercised
     * directly via a test-only subclass in unit tests without resorting to reflection.
     *
     * @param string $text The raw text potentially containing MathJax blocks.
     * @param array<string,string> $mathblocks Out parameter: placeholder to original-content map.
     * @return string The text with all MathJax blocks replaced by placeholders.
     */
    protected static function protect_math_blocks(string $text, array &$mathblocks): string {
        $counter = 0;
        $protect = static function (array $m) use (&$mathblocks, &$counter): string {
            $placeholder = self::MATHJAX_PLACEHOLDER_PREFIX . $counter . self::MATHJAX_PLACEHOLDER_SUFFIX;
            $mathblocks[$placeholder] = $m[0];
            $counter++;
            return $placeholder;
        };

        // Display math with square brackets is protected first because both display
        // and inline math start with a backslash, and a partial match against the
        // inline pattern would shadow the display variant.
        $text = preg_replace_callback('/\\\\\[(.+?)\\\\\]/s', $protect, $text);
        // Display math delimited by double dollar signs.
        $text = preg_replace_callback('/\$\$(.+?)\$\$/s', $protect, $text);
        // Inline math delimited by escaped parentheses.
        return preg_replace_callback('/\\\\\((.+?)\\\\\)/s', $protect, $text);
    }

    /**
     * Restores the MathJax blocks that were saved by {@see self::protect_math_blocks()}.
     *
     * Called AFTER markdown_to_html() so the math content reaches the browser exactly
     * as the LLM emitted it. HTMLPurifier does not run at this stage (see
     * {@see self::format_ai_markdown_output()}), so backslashes in the math are safe.
     *
     * @param string $html The HTML output of markdown_to_html().
     * @param array<string,string> $mathblocks Placeholder to original-content map.
     * @return string The HTML with all placeholders replaced by the original math.
     */
    protected static function restore_math_blocks(string $html, array $mathblocks): string {
        if (empty($mathblocks)) {
            return $html;
        }
        return str_replace(array_keys($mathblocks), array_values($mathblocks), $html);
    }

    /**
     * Stage 2 of {@see self::format_ai_markdown_output()}.
     *
     * Some LLMs emit raw pre/code HTML for code blocks instead of native Markdown
     * fences. Convert them to fences so the rest of the pipeline can treat them
     * uniformly. HTML entities inside the original code body are decoded once
     * because MarkdownExtra will re-encode them.
     *
     * @param string $markdown The Markdown input.
     * @return string The Markdown with HTML code blocks converted to fenced blocks.
     */
    protected static function html_code_blocks_to_markdown_fences(string $markdown): string {
        $fence = str_repeat(chr(96), 3);
        return preg_replace_callback(
            '/<pre>\s*<code(?:\s+class="language-(\w+)")?\s*>([\s\S]*?)<\/code>\s*<\/pre>/i',
            static function (array $matches) use ($fence): string {
                $lang = $matches[1] ?? '';
                $code = html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML401, 'UTF-8');
                return "\n\n" . $fence . $lang . "\n" . $code . "\n" . $fence . "\n\n";
            },
            $markdown
        );
    }

    /**
     * Stage 2b of {@see self::format_ai_markdown_output()}.
     *
     * Wraps any bare HTML document the LLM emitted as plain text — i.e. a buffer
     * containing a literal {@code <!doctype …>} (or {@code <!DOCTYPE …>})
     * declaration and a matching {@code </html>} closing tag — into a fenced
     * Markdown code block tagged with the {@code html} language identifier.
     *
     * Rationale: MarkdownExtra treats top-level block-level HTML as
     * pass-through, so without this stage the document's structural tags
     * (head, body, style, script, …) reach the browser verbatim while their
     * inline attribute strings and text content go through stage 4's
     * htmlspecialchars(), producing the half-live / half-entity-encoded soup
     * observed in MBS-10767 (screenshot "ganzes HTML-File falsches Parsing.png").
     *
     * The detection criterion is intentionally narrow:
     *   - it requires the literal {@code <!doctype} (case-insensitive), which any
     *     real complete document starts with;
     *   - it requires a matching {@code </html>} closing tag in the same buffer;
     *   - it does NOT touch documents that are already inside an existing
     *     ```...``` or ~~~...~~~ fence, because those have already been handled
     *     by stage 2 or by the original Markdown source.
     *
     * Anything that is "merely" a tag fragment (for example {@code <div>…</div>}
     * in prose) continues to take the existing stage-4 prose-escaping path.
     *
     * The {@code <!doctype …> … </html>} pattern is matched greedily on its
     * closing {@code </html>} on purpose: an LLM never emits two complete
     * documents in one answer, and being greedy avoids accidentally splitting
     * a single document when it contains an inline mention of {@code </html>}
     * inside a script string.
     *
     * @param string $markdown The Markdown input.
     * @return string The Markdown with bare HTML documents wrapped in fenced code blocks.
     */
    protected static function wrap_bare_html_documents_in_fences(string $markdown): string {
        // Fast path: no DOCTYPE anywhere → nothing to do.
        if (stripos($markdown, '<!doctype') === false) {
            return $markdown;
        }

        $fence = str_repeat(chr(96), 3);

        // Step 1: extract existing fenced code blocks behind opaque placeholders so the
        // DOCTYPE detector cannot reach into them. We use the same null-byte-anchored
        // placeholder scheme as stage 4 because null bytes are forbidden in JSON and
        // therefore cannot collide with any LLM output.
        $placeholders = [];
        $counter = 0;
        $placeholderprefix = self::generate_placeholder_prefix($markdown);
        $store = static function (array $m) use (&$placeholders, &$counter, $placeholderprefix): string {
            $key = $placeholderprefix . 'FENCE' . $counter++ . "\x00";
            $placeholders[$key] = $m[0];
            return $key;
        };
        $existingfencepattern = '/(' . chr(96) . '{3,}[\s\S]*?' . chr(96) . '{3,}|~{3,}[\s\S]*?~{3,})/';
        $markdown = preg_replace_callback($existingfencepattern, $store, $markdown);

        // Step 2: wrap every <!doctype …> … </html> block.
        $markdown = preg_replace_callback(
            '/<!doctype\b[^>]*>[\s\S]*<\/html\s*>/i',
            static function (array $matches) use ($fence): string {
                return "\n\n" . $fence . "html\n" . $matches[0] . "\n" . $fence . "\n\n";
            },
            $markdown
        );

        // Step 3: restore the existing fences.
        return str_replace(array_keys($placeholders), array_values($placeholders), $markdown);
    }

    /**
     * Stage 3 of {@see self::format_ai_markdown_output()}.
     *
     * Inserts blank lines in two narrow situations to make PHP Markdown Extra parse
     * the LLM's tight Markdown correctly without changing visual structure:
     *
     *   (a) The FIRST item of a tight list (asterisk or hyphen marker) is preceded
     *       by a blank line so MarkdownExtra treats the list as loose. This makes
     *       fenced code blocks INSIDE list items get recognized. We never touch
     *       subsequent list items (so the list stays one coherent ul), and we
     *       never match emphasis markers like asterisk-bold-asterisk because the
     *       regex requires whitespace AND a non-whitespace character after the
     *       marker.
     *
     *   (b) A fenced code-block opening with a language identifier that directly
     *       follows a non-empty line gets a blank line inserted before it. Without
     *       this, MarkdownExtra treats the opening fence as continuation of the
     *       previous paragraph or list item, leading to the regression where
     *       hash-include inside the code block was parsed as a Markdown h1 heading.
     *
     * Both regexes use a positive lookbehind for a non-whitespace character so
     * they only trigger when the previous character is actual content (not
     * whitespace and not a newline). This prevents matches at the very start of
     * the buffer and inside already-loose structures, which is what we want.
     *
     * @param string $markdown The Markdown input.
     * @return string The structurally normalized Markdown.
     */
    protected static function normalize_markdown_structure(string $markdown): string {
        // Stage 3a: loose-ify the FIRST item of a tight list, never the middle items.
        $markdown = preg_replace(
            '/(?<=\S)\n([ \t]*)([*-])(\s+\S)/',
            "\n\n$1$2$3",
            $markdown
        );
        // Stage 3b: loose-ify fenced code-block opening with language id following prose.
        $fenceopen = chr(96) . chr(96) . chr(96);
        return preg_replace(
            '/(?<=\S)\n([ \t]*' . preg_quote($fenceopen, '/') . '\w)/',
            "\n\n$1",
            $markdown
        );
    }

    /**
     * Stage 4 of {@see self::format_ai_markdown_output()}.
     *
     * Removes the possibility of any raw HTML tag from the LLM reaching the browser
     * as executable HTML, while keeping code regions and blockquote markers intact.
     *
     * Algorithm:
     *   1. Extract every fenced code block, inline code span and blockquote line
     *      prefix and replace each with a unique null-byte-anchored placeholder.
     *      Null bytes are forbidden in JSON, so they cannot occur in any LLM output
     *      and collisions are impossible.
     *   2. Run htmlspecialchars on the remaining (prose) text. double_encode=false
     *      avoids double-escaping entities the LLM emitted correctly (for example
     *      an ampersand entity). Moodle's s() cannot be used here because it always
     *      double-encodes.
     *   3. Restore the placeholders.
     *
     * After this stage, no live HTML tag exists outside MarkdownExtra-managed code
     * regions, where MarkdownExtra itself encodes everything safely.
     *
     * @param string $markdown The Markdown input.
     * @return string The Markdown with raw HTML outside code regions HTML-escaped.
     */
    protected static function neutralize_raw_html_outside_code(string $markdown): string {
        $placeholders = [];
        $counter = 0;
        $placeholderprefix = self::generate_placeholder_prefix($markdown);

        $store = static function (array $m) use (&$placeholders, &$counter, $placeholderprefix): string {
            $key = $placeholderprefix . $counter++ . "\x00";
            $placeholders[$key] = $m[0];
            return $key;
        };

        // Fenced code blocks (triple-backtick or triple-tilde) and inline code spans.
        $codepattern = '/(' . chr(96) . '{3,}[\s\S]*?' . chr(96) . '{3,}|~{3,}[\s\S]*?~{3,}|'
            . chr(96) . '[^' . chr(96) . '\n]+' . chr(96) . ')/';
        $markdown = preg_replace_callback($codepattern, $store, $markdown);
        // Blockquote line-prefix markers (one or more closing-angle brackets at line start,
        // possibly indented).
        $markdown = preg_replace_callback('/^(\s*>)+/m', $store, $markdown);

        // Escape everything that is now NOT a placeholder.
        $markdown = htmlspecialchars(
            $markdown,
            ENT_QUOTES | ENT_HTML401 | ENT_SUBSTITUTE,
            'UTF-8',
            false
        );

        // Restore the placeholders.
        return str_replace(array_keys($placeholders), array_values($placeholders), $markdown);
    }

    /**
     * Escapes MathJax begin/end environment patterns outside pre blocks in HTML.
     *
     * MathJax v3 picks these patterns up anywhere in the page DOM and tries to
     * render them as math environments. When the LLM emits LaTeX source code in
     * prose context (for example a tutorial about LaTeX containing
     * begin-document), MathJax would incorrectly interpret that as displayable
     * math. We wrap such patterns in a mathjax_ignore span so MathJax leaves
     * them alone. Content inside pre is not modified because MathJax already
     * skips pre by default.
     *
     * @param string $html The HTML to process.
     * @return string The HTML with begin/end environment markers outside pre wrapped.
     */
    public static function escape_mathjax_environments(string $html): string {
        $parts = preg_split(
            '/(<pre[\s>][\s\S]*?<\/pre>)/i',
            $html,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        for ($i = 0, $count = count($parts); $i < $count; $i++) {
            // Even indices are outside pre blocks; odd indices are matched pre blocks.
            if ($i % 2 === 0) {
                $parts[$i] = preg_replace(
                    '/\\\\(begin|end)\{[^}]*}/',
                    '<span class="mathjax_ignore">$0</span>',
                    $parts[$i]
                );
            }
        }
        return implode('', $parts);
    }

    /**
     * Stage 5b of {@see self::format_ai_markdown_output()}.
     *
     * Strips MarkdownExtra's spurious leading and trailing newlines inside
     * {@code <pre><code>…</code></pre>} blocks. MarkdownExtra emits e.g.
     * {@code <pre><code class="python">\nprint("hi")\n</code></pre>}, which
     * the browser renders with a visible blank line above and below the code
     * content. Originally introduced before the MBS-10767 pipeline refactor;
     * lost during the rewrite into the six-stage pipeline; restored here
     * with the original intent documented inline.
     *
     * Only the leading and trailing newlines of the code body are trimmed:
     * any blank lines in the *middle* of the code are part of the user's
     * source and must remain untouched.
     *
     * @param string $html HTML produced by markdown_to_html() with optional
     *      post-processing already applied.
     * @return string HTML with the spurious newlines stripped.
     */
    protected static function strip_blank_lines_inside_code_blocks(string $html): string {
        return preg_replace_callback(
            '#(<pre>\s*<code(?:\s+class="[^"]*")?\s*>)([\s\S]*?)(</code>\s*</pre>)#i',
            static function (array $m): string {
                return $m[1] . trim($m[2], "\n") . $m[3];
            },
            $html
        );
    }

    /**
     * Generates a unique placeholder prefix string that does not occur in the given text.
     *
     * The default prefix starts with a NULL byte which never occurs in normal LLM
     * output (JSON forbids it). If a collision is detected anyway, an X is appended
     * deterministically until the prefix is unique.
     *
     * @param string $text The text to check for collisions.
     * @return string A placeholder prefix guaranteed not to appear in the text.
     */
    public static function generate_placeholder_prefix(string $text): string {
        $placeholderprefix = "\x00PLACEHOLDER";
        while (str_contains($text, $placeholderprefix)) {
            $placeholderprefix .= 'X';
        }
        return $placeholderprefix;
    }

    /**
     * Formats the given prompt text based on the provided sanitized options.
     *
     * @param string $prompttext The prompt text to be formatted.
     * @param request_options $requestoptions The request options object. Reserved
     *      for use by subclasses; the base implementation does not consume it.
     * @return string The formatted prompt text as string.
     */
    public function format_prompt_text(string $prompttext, request_options $requestoptions): string {
        unset($requestoptions);
        return $prompttext;
    }
}

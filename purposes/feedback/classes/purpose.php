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
 * Purpose feedback methods.
 *
 * @package    aipurpose_feedback
 * @copyright  ISB Bayern, 2024
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aipurpose_feedback;

use local_ai_manager\base_purpose;

/**
 * Purpose feedback methods.
 *
 * The feedback purpose formats AI-generated feedback for student submissions.
 * It relies on the base_purpose's format_output() which handles MathJax/LaTeX
 * protection, Markdown-to-HTML conversion, and XSS sanitization.
 *
 * @package    aipurpose_feedback
 * @copyright  ISB Bayern, 2024
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purpose extends base_purpose {
    #[\Override]
    public function get_additional_request_options(array $options): array {
        return [];
    }

    // No override of format_output() needed.
    // The parent's format_output() handles:
    //   1. MathJax/LaTeX protection (placeholder extraction before Markdown parsing)
    //   2. Markdown-to-HTML conversion via markdown_to_html()
    //   3. XSS sanitization via format_text()
    //
    // The previous clean_text() call destroyed LaTeX backslashes (\frac -> frac,
    // \vec -> vec, \( -> () and did not convert Markdown to HTML.
}

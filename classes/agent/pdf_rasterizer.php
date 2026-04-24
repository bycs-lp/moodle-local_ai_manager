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
 * Ghostscript-backed PDF utilities (MBS-10761 Paket 7).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent;

/**
 * Wraps the `gs` CLI for two operations:
 *   - extract_text(): run the txtwrite device to pull embedded text from a PDF.
 *   - rasterise_pages(): run the pngalpha device to render PNG per page for OCR.
 *
 * Split out behind an injectable service so tests can bypass ghostscript by
 * binding a fake via \core\di::set().
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pdf_rasterizer {

    /** Default ghostscript binary path. */
    private const GS_BIN = '/usr/bin/gs';

    /**
     * Count the number of pages of a PDF. Returns 0 on failure.
     *
     * @param string $pdfpath Absolute path to the PDF file.
     * @return int
     */
    public function get_page_count(string $pdfpath): int {
        if (!is_readable($pdfpath) || !$this->gs_available()) {
            return 0;
        }
        $cmd = escapeshellcmd(self::GS_BIN) . ' -q -dNODISPLAY -dNOSAFER '
            . '-c "(' . $pdfpath . ') (r) file runpdfbegin pdfpagecount = quit" 2>/dev/null';
        $out = trim((string) shell_exec($cmd));
        if ($out !== '' && ctype_digit($out)) {
            return (int) $out;
        }
        return 0;
    }

    /**
     * Extract embedded text from a PDF using the ghostscript `txtwrite` device.
     * Returns an empty string if the PDF has no (or unreadable) embedded text.
     *
     * @param string $pdfpath Absolute path to the PDF file.
     * @return string
     */
    public function extract_text(string $pdfpath): string {
        if (!is_readable($pdfpath) || !$this->gs_available()) {
            return '';
        }
        $outfile = make_request_directory() . '/extracted.txt';
        $cmd = escapeshellcmd(self::GS_BIN)
            . ' -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=txtwrite'
            . ' -sOutputFile=' . escapeshellarg($outfile)
            . ' ' . escapeshellarg($pdfpath)
            . ' 2>/dev/null';
        shell_exec($cmd);
        if (!is_readable($outfile)) {
            return '';
        }
        return (string) file_get_contents($outfile);
    }

    /**
     * Rasterise the first $maxpages pages of the given PDF to PNG files.
     *
     * @param string $pdfpath Absolute path to the PDF file.
     * @param int $maxpages Maximum number of pages to render (>= 1).
     * @param int $resolutiondpi Output DPI (default 150).
     * @return string[] Absolute paths of generated PNG files, in page order.
     *                  Empty array on failure.
     */
    public function rasterise_pages(string $pdfpath, int $maxpages, int $resolutiondpi = 150): array {
        if ($maxpages < 1 || !is_readable($pdfpath) || !$this->gs_available()) {
            return [];
        }
        $dir = make_request_directory();
        $pattern = $dir . '/page_%03d.png';
        $cmd = escapeshellcmd(self::GS_BIN)
            . ' -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=pngalpha'
            . ' -dFirstPage=1 -dLastPage=' . (int) $maxpages
            . ' -r' . (int) $resolutiondpi
            . ' -sOutputFile=' . escapeshellarg($pattern)
            . ' ' . escapeshellarg($pdfpath)
            . ' 2>/dev/null';
        shell_exec($cmd);
        $files = glob($dir . '/page_*.png') ?: [];
        sort($files);
        return $files;
    }

    /**
     * Whether the ghostscript binary is present and executable.
     *
     * @return bool
     */
    public function gs_available(): bool {
        return is_executable(self::GS_BIN);
    }
}

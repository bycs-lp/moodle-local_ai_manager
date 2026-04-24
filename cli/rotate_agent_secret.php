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
 * Rotate the HMAC secret used to sign approval tokens (MBS-10761 §9.2).
 *
 * Rotating invalidates all in-flight approval tokens: any pending tool call
 * must be re-approved by the user after the rotation. Running agent runs stay
 * intact; they only fail on the next approval attempt.
 *
 * Usage:
 *   php cli/rotate_agent_secret.php --force      # rotate without prompt
 *   php cli/rotate_agent_secret.php --show       # print the current secret length, do not rotate
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'help' => false,
        'force' => false,
        'show' => false,
    ],
    [
        'h' => 'help',
        'f' => 'force',
    ]
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error("Unrecognised options:\n  {$unrecognized}", 2);
}

if ($options['help']) {
    echo "Rotate local_ai_manager HMAC secret\n\n"
        . "Options:\n"
        . "  -f, --force   Rotate without interactive confirmation.\n"
        . "      --show    Print the current secret length and exit.\n"
        . "  -h, --help    Show this help.\n";
    exit(0);
}

$currentsecret = (string) get_config('local_ai_manager', 'agent_hmac_secret');

if ($options['show']) {
    if ($currentsecret === '') {
        cli_writeln('No agent HMAC secret is currently set.');
    } else {
        cli_writeln('Current secret length: ' . strlen($currentsecret) . ' bytes');
    }
    exit(0);
}

if (!$options['force']) {
    $answer = cli_input(
        'This will invalidate all in-flight approval tokens. Rotate HMAC secret? (y/N): ',
        'n',
        ['y', 'n', 'Y', 'N']
    );
    if (strtolower(trim($answer)) !== 'y') {
        cli_writeln('Aborted.');
        exit(0);
    }
}

$newsecret = random_string(64);
set_config('agent_hmac_secret', $newsecret, 'local_ai_manager');

cli_writeln('HMAC secret rotated. Old length: ' . strlen($currentsecret)
    . ', new length: ' . strlen($newsecret));
exit(0);

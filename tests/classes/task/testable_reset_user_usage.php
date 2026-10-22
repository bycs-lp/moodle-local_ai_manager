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

namespace local_ai_manager\tests\task;

use local_ai_manager\task\reset_user_usage;

/**
 * Testable version of the reset_user_usage task with a small batch size and recorded instead of real waits.
 *
 * @package   local_ai_manager
 * @copyright 2026 ISB Bayern
 * @author    Philipp Memmel
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class testable_reset_user_usage extends reset_user_usage {
    /** @var int Small batch size so that multiple batches can be tested with few records. */
    public const BATCH_SIZE = 10;

    /** @var int[] The delays in microseconds the task requested to wait, in calling order. */
    public array $waits = [];

    #[\Override]
    protected function wait(int $microseconds): void {
        $this->waits[] = $microseconds;
    }
}

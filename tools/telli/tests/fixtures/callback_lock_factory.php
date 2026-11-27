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

namespace aitool_telli\fixtures;

use core\lock\lock;
use core\lock\lock_factory;

/**
 * Lock factory for tests which runs a callback before it grants a lock.
 *
 * The callback simulates a concurrent request which finishes its work while the current request waits for the lock.
 *
 * @package    aitool_telli
 * @copyright  2026 ISB Bayern
 * @author     Thomas Schönlein
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class callback_lock_factory implements lock_factory {
    /** @var \Closure|null callback which is run before a lock is granted */
    public static ?\Closure $beforelock = null;

    /**
     * Constructor.
     *
     * @param string $type the lock type
     */
    public function __construct($type) {
    }

    #[\Override]
    public function supports_timeout() {
        return true;
    }

    #[\Override]
    public function supports_auto_release() {
        return true;
    }

    #[\Override]
    public function is_available() {
        return true;
    }

    #[\Override]
    public function get_lock($resource, $timeout, $maxlifetime = 86400) {
        if (self::$beforelock !== null) {
            $callback = self::$beforelock;
            $callback();
        }
        return new lock($resource, $this);
    }

    #[\Override]
    public function release_lock(lock $lock) {
        return true;
    }
}

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

namespace local_ai_manager\hook;

use context;
use local_ai_manager\base_purpose;
use local_ai_manager\local\userinfo;

/**
 * Hook for allowing other plugins to further restrict the access to use the AI tools through the local_ai_manager.
 *
 * This hook will be dispatched whenever a user tries to send a request to the AI tool via local_ai_manager.
 *
 * @package    local_ai_manager
 * @copyright  2024 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to further restrict the use of AI tools through local_ai_manager.')]
#[\core\attribute\tags('local_ai_manager')]
class additional_user_restriction {
    /** @var bool If access to the AI tool should be granted or not */
    private bool $allowed = true;
    /** @var int The corresponding HTTP status code, 200 if access is being granted */
    private int $code = 200;
    /** @var string A localized error message that is being shown to the user in case of an error */
    private string $message = '';
    /** @var string Optional debug info */
    private string $debuginfo = '';

    /**
     * Constructor for the hook.
     */
    public function __construct(
        /** @var userinfo The userinfo object of the user that tries to access an AI tool */
        private readonly userinfo $userinfo,
        /** @var ?context The context or null if no context has been specified */
        private readonly ?context $context,
        /** @var base_purpose The purpose which is being tried to use */
        private readonly base_purpose $purpose,
    ) {
    }

    /**
     * Getter for the userinfo object.
     *
     * @return userinfo The userinfo object of the user trying to access the AI tool
     */
    public function get_userinfo(): userinfo {
        return $this->userinfo;
    }

    /**
     * Getter for the current context.
     *
     * @return ?context the context from which the AI tool is being tried to access
     */
    public function get_context(): ?context {
        return $this->context;
    }

    /**
     * Getter for the currently used purpose.
     *
     * @return base_purpose The purpose being used
     */
    public function get_purpose(): base_purpose {
        return $this->purpose;
    }

    /**
     * Set if the access for the current user should be denied or not.
     *
     * If access is granted, you do not need to do anything, because it is the default.
     * If access should be restricted, pass $allowed = false and also provide a code !== 200 as well as an
     * already localized error message that is being used as feedback to the user
     *
     * @param bool $allowed true if access is granted, false otherwise
     * @param int $code an HTTP status code that should be returned to the user, will be set to 200 in case of
     *  $allowed = true
     * @param string $message localized message that should be shown to the user in case of restricted access
     * @param string $debuginfo optional debug info
     */
    public function set_access_allowed(bool $allowed, int $code = 0, string $message = '', string $debuginfo = ''): void {
        if ($allowed) {
            $this->allowed = true;
            $this->code = 200;
            return;
        }

        $this->allowed = false;
        if ($code === 200) {
            throw new \coding_exception('You have to provide a different code than 200 in case of a denied access.');
        }
        $this->code = $code;
        if (empty($message)) {
            throw new \coding_exception('You have to provide a message in case of a denied access.');
        }
        $this->message = $message;
        $this->debuginfo = $debuginfo;
    }

    /**
     * Standard getter for the allowed attribute.
     *
     * @return bool if access is being granted or not
     */
    public function is_allowed(): bool {
        return $this->allowed;
    }

    /**
     * Standard getter for the corresponding HTTP status code.
     *
     * @return int the HTTP status code
     */
    public function get_code(): int {
        return $this->code;
    }

    /**
     * Standard getter for the message in case of an error.
     *
     * @return string the error message
     */
    public function get_message(): string {
        return $this->message;
    }

    /**
     * Standard getter for the optional debug info.
     *
     * @return string the debug info
     */
    public function get_debuginfo(): string {
        return $this->debuginfo;
    }
}

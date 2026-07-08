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

namespace local_ai_manager\local;

/**
 * Data object class for storing vecstore response information in a defined way.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class vecstore_response {
    /** @var int The status code of the response. */
    private int $code;

    /** @var string If there has been an error, this variable contains the error message. */
    private string $errormessage = '';

    /** @var string If there has been an error, this variable contains additional debugging information. */
    private string $debuginfo;

    /** @var ?vecstore_query_response Query payload if this response represents a query-like operation. */
    private ?vecstore_query_response $queryresponse = null;

    /**
     * Private constructor to avoid object creation without static create function.
     */
    private function __construct() {
    }

    /**
     * Standard setter.
     *
     * @param int $code the status code of the response
     */
    public function set_code(int $code): void {
        $this->code = $code;
    }

    /**
     * Standard setter.
     *
     * @param string $errormessage the error message to store
     */
    public function set_errormessage(string $errormessage): void {
        $this->errormessage = $errormessage;
    }

    /**
     * Standard setter.
     *
     * @param string $debuginfo the debug info to store
     */
    public function set_debuginfo(string $debuginfo): void {
        $this->debuginfo = $debuginfo;
    }

    /**
     * Standard setter.
     *
     * @param vecstore_query_response $queryresponse the query response payload
     */
    public function set_queryresponse(vecstore_query_response $queryresponse): void {
        $this->queryresponse = $queryresponse;
    }

    /**
     * Standard getter.
     *
     * @return int the status code of the response
     */
    public function get_code(): int {
        return $this->code;
    }

    /**
     * Standard getter.
     *
     * @return string the error message (can be empty if there were no errors)
     */
    public function get_errormessage(): string {
        return $this->errormessage;
    }

    /**
     * Standard getter.
     *
     * @return string the debug info (can be empty if there were no errors)
     */
    public function get_debuginfo(): string {
        return $this->debuginfo;
    }

    /**
     * Standard getter.
     *
     * @return ?vecstore_query_response the query payload or null for non-query operations
     */
    public function get_queryresponse(): ?vecstore_query_response {
        return $this->queryresponse;
    }

    /**
     * Static create function for a vecstore_response object in case of an error.
     *
     * @param int $code the status code of the response
     * @param string $errormessage the error message
     * @param string $debuginfo the debug info
     * @return vecstore_response the vecstore_response object containing the error information
     */
    public static function create_from_error(int $code, string $errormessage, string $debuginfo): vecstore_response {
        if ($code === 200) {
            throw new \coding_exception('You cannot create an error with code 200');
        }
        $vecstoreresponse = new self();
        $vecstoreresponse->set_code($code);
        $vecstoreresponse->set_errormessage($errormessage);
        $vecstoreresponse->set_debuginfo($debuginfo);
        return $vecstoreresponse;
    }

    /**
     * Static create function for a vecstore_response object in case of a successful non-query operation.
     *
     * @return vecstore_response the vecstore_response object representing a successful operation
     */
    public static function create_from_result(): vecstore_response {
        $vecstoreresponse = new self();
        $vecstoreresponse->set_code(200);
        return $vecstoreresponse;
    }

    /**
     * Static create function for a vecstore_response object in case of a successful query operation.
     *
     * @param vecstore_query_response $queryresponse the query payload object
     * @return vecstore_response the vecstore_response object containing the query payload
     */
    public static function create_from_query_result(vecstore_query_response $queryresponse): vecstore_response {
        $vecstoreresponse = self::create_from_result();
        $vecstoreresponse->set_queryresponse($queryresponse);
        return $vecstoreresponse;
    }
}


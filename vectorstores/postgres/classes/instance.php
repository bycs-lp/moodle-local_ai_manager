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

namespace aivecstore_postgres;

use local_ai_manager\base_vecstore_instance;

/**
 * Vector store instance class for the PostgreSQL/pgvector backend.
 *
 * PostgreSQL uses the shared connection fields of {@see base_vecstore_instance} like Qdrant: the endpoint is the
 * database server as URL (postgresql://host:port/database, optionally with the parameter sslmode), the user name has its
 * own field and the password is stored in the (masked) secret field. The libpq connection string is built from these
 * values when connecting, so no credentials are part of a visible or stored connection string.
 *
 * @package    aivecstore_postgres
 * @copyright  2026 Exputo Inc.
 * @author     David Pesce <david.pesce@exputo.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class instance extends base_vecstore_instance {
    /** @var string[] The ssl modes which guarantee an encrypted connection. */
    private const SECURE_SSLMODES = ['require', 'verify-ca', 'verify-full'];

    /** @var int The port of PostgreSQL servers if the endpoint does not contain one. */
    private const DEFAULT_PORT = 5432;

    #[\Override]
    protected function extend_form_definition(\MoodleQuickForm $mform): void {
        // Adapt the shared endpoint field: it is not an HTTP URL.
        $endpointelement = $mform->getElement('endpoint');
        $endpointelement->setLabel(get_string('endpoint', 'aivecstore_postgres'));
        $endpointelement->updateAttributes(['placeholder' => 'postgresql://your-db-host:5432/dbname']);
        $mform->setType('endpoint', PARAM_RAW_TRIMMED);
        $mform->getElement('endpointdescription')->setText(get_string('endpointdescription', 'aivecstore_postgres'));
    }

    #[\Override]
    protected function uses_username(): bool {
        return true;
    }

    #[\Override]
    protected function get_secret_label(): string {
        return get_string('password', 'aivecstore_postgres');
    }

    #[\Override]
    protected function extend_validation(array $data, array $files): array {
        $errors = [];
        if (empty($data['endpoint'])) {
            $errors['endpoint'] = get_string('formvalidation_endpoint', 'aivecstore_postgres');
        }
        if (empty($data['username'])) {
            $errors['username'] = get_string('formvalidation_username', 'aivecstore_postgres');
        }
        return $errors;
    }

    #[\Override]
    public function validate_endpoint(string $endpoint): ?string {
        $parts = $this->parse_endpoint($endpoint);
        if ($parts === null) {
            return get_string('formvalidation_endpointformat', 'aivecstore_postgres');
        }
        if (
            $parts['sslmode'] !== null
            && !in_array($parts['sslmode'], self::SECURE_SSLMODES)
            && !$this->is_insecure_allowed()
        ) {
            return get_string('formvalidation_endpointsslmode', 'aivecstore_postgres');
        }
        // Apply the Moodle cURL security settings (blocked hosts, allowed ports) like for the HTTP based backends.
        $host = str_contains($parts['host'], ':') ? '[' . $parts['host'] . ']' : $parts['host'];
        $helper = new \core\files\curl_security_helper();
        if ($helper->url_is_blocked('http://' . $host . ':' . $parts['port'] . '/')) {
            return $helper->get_blocked_url_string();
        }
        return null;
    }

    /**
     * Builds the libpq connection string from the endpoint, the user name and the password of the instance.
     *
     * The endpoint is validated again, so a connection is never opened with values that would not pass the form validation.
     * If insecure connections are not allowed, an encrypted connection is required even if the endpoint does not
     * specify an ssl mode.
     *
     * @return string the libpq connection string
     * @throws \moodle_exception if the endpoint must not be used
     */
    public function get_connection_string(): string {
        $endpoint = trim((string) $this->get_endpoint());
        $error = $this->validate_endpoint($endpoint);
        if ($error !== null) {
            throw new \moodle_exception('connectionrefused', 'aivecstore_postgres', '', $error);
        }
        $parts = $this->parse_endpoint($endpoint);
        if ($parts['sslmode'] === null && !$this->is_insecure_allowed()) {
            $parts['sslmode'] = 'require';
        }

        $values = [
            'host' => $parts['host'],
            'port' => $parts['port'],
            'dbname' => $parts['dbname'],
            'user' => $this->get_username(),
            'password' => $this->get_secret(),
            'sslmode' => $parts['sslmode'],
        ];
        $pairs = [];
        foreach ($values as $key => $value) {
            if ($value !== null && $value !== '') {
                $pairs[] = $key . "='" . addcslashes((string) $value, "'\\") . "'";
            }
        }
        return implode(' ', $pairs);
    }

    /**
     * Returns whether the admin allows insecure (unencrypted) vector database connections.
     *
     * @return bool true if insecure connections are allowed
     */
    protected function is_insecure_allowed(): bool {
        return !empty(get_config('local_ai_manager', 'allowinsecurevecstore'));
    }

    /**
     * Splits an endpoint of the form postgresql://host[:port]/dbname[?sslmode=mode] into its parts.
     *
     * Credentials and all other parameters are rejected: they have own fields or could redirect the connection
     * (e.g. host, service, sslrootcert).
     *
     * @param string $endpoint the endpoint
     * @return ?array the parts (host, port, dbname, sslmode which is null if not specified), null if the format is invalid
     */
    protected function parse_endpoint(string $endpoint): ?array {
        $url = parse_url($endpoint);
        if (
            $url === false
            || !in_array($url['scheme'] ?? '', ['postgresql', 'postgres'])
            || empty($url['host'])
            || isset($url['user'])
            || isset($url['pass'])
            || isset($url['fragment'])
        ) {
            return null;
        }
        $dbname = trim($url['path'] ?? '', '/');
        if ($dbname === '' || str_contains($dbname, '/')) {
            return null;
        }
        $query = [];
        parse_str($url['query'] ?? '', $query);
        if (array_diff(array_keys($query), ['sslmode']) || !is_string($query['sslmode'] ?? '')) {
            return null;
        }
        return [
            'host' => trim($url['host'], '[]'),
            'port' => $url['port'] ?? self::DEFAULT_PORT,
            'dbname' => rawurldecode($dbname),
            'sslmode' => $query['sslmode'] ?? null,
        ];
    }
}

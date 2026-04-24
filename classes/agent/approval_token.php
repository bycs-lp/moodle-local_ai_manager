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
 * HMAC approval-token issuer/verifier (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent;

use local_ai_manager\agent\exception\invalid_token_exception;

/**
 * Issues and verifies HMAC-signed approval tokens for pending tool calls.
 *
 * Token shape (base64url):
 *   payload = runid|callindex|userid|argshash|expires
 *   token   = base64url( expires . "." . hash_hmac('sha256', payload, secret) )
 *
 * The secret lives in {@code local_ai_manager/agent_hmac_secret} and is bootstrapped
 * by the plugin upgrade step. Rotation invalidates all pending approvals.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class approval_token {

    /** Default token lifetime in seconds if not configured otherwise. */
    public const DEFAULT_TTL = 900;

    /**
     * Constructor.
     *
     * @param string $secret HMAC secret (usually from get_config('local_ai_manager', 'agent_hmac_secret'))
     * @param \core\clock $clock injected clock for deterministic expiry tests
     */
    public function __construct(
        private readonly string $secret,
        private readonly \core\clock $clock,
    ) {
        if ($secret === '') {
            throw new \coding_exception('approval_token requires a non-empty secret.');
        }
    }

    /**
     * Construct a token issuer using the site-configured secret.
     *
     * Bootstraps the secret on first use if it does not yet exist — useful during tests
     * that do not run the upgrade step.
     *
     * @return self
     */
    public static function instance(): self {
        $secret = (string) get_config('local_ai_manager', 'agent_hmac_secret');
        if ($secret === '') {
            $secret = random_string(64);
            set_config('agent_hmac_secret', $secret, 'local_ai_manager');
        }
        $clock = \core\di::get(\core\clock::class);
        return new self($secret, $clock);
    }

    /**
     * Issue a token for a pending tool call.
     *
     * @param int $runid
     * @param int $callindex
     * @param int $userid
     * @param string $argshash sha256 of the canonical args JSON
     * @return string base64url token
     */
    public function issue(int $runid, int $callindex, int $userid, string $argshash): string {
        $ttl = (int) get_config('local_ai_manager', 'agent_approval_ttl');
        if ($ttl <= 0) {
            $ttl = self::DEFAULT_TTL;
        }
        $expires = $this->clock->now()->getTimestamp() + $ttl;
        $payload = $this->canonical_payload($runid, $callindex, $userid, $argshash, $expires);
        $mac = hash_hmac('sha256', $payload, $this->secret);
        return self::base64url_encode($expires . '.' . $mac);
    }

    /**
     * Verify a token against a tool call.
     *
     * @param string $token
     * @param int $runid
     * @param int $callindex
     * @param int $userid
     * @param string $argshash
     * @throws invalid_token_exception on malformed, expired or tampered tokens
     */
    public function verify(string $token, int $runid, int $callindex, int $userid, string $argshash): void {
        $decoded = self::base64url_decode($token);
        if ($decoded === null || !str_contains($decoded, '.')) {
            throw new invalid_token_exception('malformed');
        }
        [$expiresraw, $mac] = explode('.', $decoded, 2);
        if ($expiresraw === '' || !ctype_digit($expiresraw) || $mac === '') {
            throw new invalid_token_exception('malformed');
        }
        $expires = (int) $expiresraw;
        if ($expires < $this->clock->now()->getTimestamp()) {
            throw new invalid_token_exception('expired');
        }
        $payload = $this->canonical_payload($runid, $callindex, $userid, $argshash, $expires);
        $expected = hash_hmac('sha256', $payload, $this->secret);
        if (!hash_equals($expected, $mac)) {
            throw new invalid_token_exception('invalid');
        }
    }

    /**
     * Compute the stable sha256 hash of a canonical args array.
     *
     * The hash is used both in the token payload and as a DB column so approvals can
     * be tied to the exact arguments presented to the user — argument tampering after
     * the approval dialog invalidates the token.
     *
     * @param array $args
     * @return string 64-char lowercase hex
     */
    public static function hash_args(array $args): string {
        // Canonicalise: deterministic key order + UNESCAPED_UNICODE keeps non-ASCII stable.
        $canonical = self::canonicalise($args);
        $encoded = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \coding_exception('Could not canonicalise tool arguments for hashing.');
        }
        return hash('sha256', $encoded);
    }

    /**
     * Recursively sort associative arrays by key to produce a deterministic structure.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function canonicalise(mixed $value): mixed {
        if (!is_array($value)) {
            return $value;
        }
        $isassoc = array_keys($value) !== range(0, count($value) - 1);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::canonicalise($v);
        }
        if ($isassoc) {
            ksort($out);
        }
        return $out;
    }

    /**
     * Build the canonical payload string used for signing.
     *
     * @param int $runid
     * @param int $callindex
     * @param int $userid
     * @param string $argshash
     * @param int $expires
     * @return string
     */
    private function canonical_payload(int $runid, int $callindex, int $userid, string $argshash, int $expires): string {
        return "{$runid}|{$callindex}|{$userid}|{$argshash}|{$expires}";
    }

    /**
     * URL-safe base64 encode (no padding).
     *
     * @param string $data
     * @return string
     */
    private static function base64url_encode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * URL-safe base64 decode. Returns null on failure.
     *
     * @param string $data
     * @return string|null
     */
    private static function base64url_decode(string $data): ?string {
        $translated = strtr($data, '-_', '+/');
        $pad = strlen($translated) % 4;
        if ($pad > 0) {
            $translated .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($translated, true);
        return $decoded === false ? null : $decoded;
    }
}

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
 * Unit tests for the HMAC approval_token issuer/verifier (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent;

use local_ai_manager\agent\exception\invalid_token_exception;

/**
 * Tests the full lifecycle of {@see approval_token}.
 *
 * Target coverage: 100% (SPEZ §15.8 hard requirement for security-critical code).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_manager\agent\approval_token
 */
final class approval_token_test extends \advanced_testcase {

    /**
     * Build an issuer with a frozen clock so TTL logic is deterministic.
     *
     * @param int $now
     * @return array{0: approval_token, 1: \core\clock}
     */
    private function issuer(int $now = 1700000000): array {
        $clock = $this->mock_clock_with_frozen($now);
        set_config('agent_approval_ttl', 900, 'local_ai_manager');
        return [new approval_token('test-secret-xyz', $clock), $clock];
    }

    /**
     * @covers \local_ai_manager\agent\approval_token::issue
     * @covers \local_ai_manager\agent\approval_token::verify
     */
    public function test_issue_and_verify_happy_path(): void {
        $this->resetAfterTest();
        [$token] = $this->issuer();
        $hash = approval_token::hash_args(['foo' => 'bar']);
        $issued = $token->issue(10, 0, 42, $hash);
        $this->assertNotEmpty($issued);

        // Verify must not throw.
        $token->verify($issued, 10, 0, 42, $hash);
        $this->addToAssertionCount(1);
    }

    /**
     * @covers \local_ai_manager\agent\approval_token::verify
     */
    public function test_verify_rejects_tampered_args_hash(): void {
        $this->resetAfterTest();
        [$token] = $this->issuer();
        $issued = $token->issue(10, 0, 42, approval_token::hash_args(['foo' => 'bar']));

        $this->expectException(invalid_token_exception::class);
        $token->verify($issued, 10, 0, 42, approval_token::hash_args(['foo' => 'baz']));
    }

    /**
     * @covers \local_ai_manager\agent\approval_token::verify
     */
    public function test_verify_rejects_expired_token(): void {
        $this->resetAfterTest();
        $clock = $this->mock_clock_with_frozen(1700000000);
        set_config('agent_approval_ttl', 60, 'local_ai_manager');
        $token = new approval_token('s', $clock);
        $issued = $token->issue(1, 0, 1, 'h');

        // Advance past TTL.
        $clock->bump(3600);
        try {
            $token->verify($issued, 1, 0, 1, 'h');
            $this->fail('Expected expired token exception.');
        } catch (invalid_token_exception $e) {
            $this->assertSame('expired', $e->reason);
        }
    }

    /**
     * @covers \local_ai_manager\agent\approval_token::verify
     */
    public function test_verify_rejects_malformed_token(): void {
        $this->resetAfterTest();
        [$token] = $this->issuer();
        foreach (['', 'garbage', '!!!not-base64!!!', 'YWJjZGVm'] as $bad) {
            try {
                $token->verify($bad, 1, 0, 1, 'h');
                $this->fail("Expected malformed/invalid exception for '$bad'");
            } catch (invalid_token_exception $e) {
                $this->assertContains($e->reason, ['malformed', 'invalid']);
            }
        }
    }

    /**
     * @covers \local_ai_manager\agent\approval_token::verify
     */
    public function test_verify_rejects_wrong_userid(): void {
        $this->resetAfterTest();
        [$token] = $this->issuer();
        $hash = approval_token::hash_args(['x' => 1]);
        $issued = $token->issue(5, 0, 100, $hash);

        $this->expectException(invalid_token_exception::class);
        $token->verify($issued, 5, 0, 101, $hash);
    }

    /**
     * Secret rotation must invalidate all previously issued tokens.
     *
     * @covers \local_ai_manager\agent\approval_token::verify
     */
    public function test_secret_rotation_invalidates_tokens(): void {
        $this->resetAfterTest();
        $clock = $this->mock_clock_with_frozen(1700000000);
        set_config('agent_approval_ttl', 900, 'local_ai_manager');
        $issuer1 = new approval_token('old-secret', $clock);
        $issued = $issuer1->issue(1, 0, 1, 'h');

        $issuer2 = new approval_token('new-secret', $clock);
        $this->expectException(invalid_token_exception::class);
        $issuer2->verify($issued, 1, 0, 1, 'h');
    }

    /**
     * @covers \local_ai_manager\agent\approval_token::hash_args
     */
    public function test_hash_args_is_stable_across_key_order(): void {
        $this->resetAfterTest();
        $a = approval_token::hash_args(['b' => 2, 'a' => 1]);
        $b = approval_token::hash_args(['a' => 1, 'b' => 2]);
        $this->assertSame($a, $b);

        // Nested associative arrays are canonicalised recursively.
        $c = approval_token::hash_args(['outer' => ['z' => 1, 'a' => 2]]);
        $d = approval_token::hash_args(['outer' => ['a' => 2, 'z' => 1]]);
        $this->assertSame($c, $d);

        // But list arrays preserve order (semantically significant).
        $e = approval_token::hash_args(['items' => [1, 2, 3]]);
        $f = approval_token::hash_args(['items' => [3, 2, 1]]);
        $this->assertNotSame($e, $f);
    }

    /**
     * @covers \local_ai_manager\agent\approval_token::__construct
     */
    public function test_constructor_rejects_empty_secret(): void {
        $this->resetAfterTest();
        $clock = $this->mock_clock_with_frozen(1700000000);
        $this->expectException(\coding_exception::class);
        new approval_token('', $clock);
    }

    /**
     * @covers \local_ai_manager\agent\approval_token::instance
     */
    public function test_instance_bootstraps_secret_on_first_call(): void {
        $this->resetAfterTest();
        unset_config('agent_hmac_secret', 'local_ai_manager');
        $this->mock_clock_with_frozen(1700000000);

        $t = approval_token::instance();
        $this->assertInstanceOf(approval_token::class, $t);
        $this->assertNotEmpty(get_config('local_ai_manager', 'agent_hmac_secret'));
    }
}

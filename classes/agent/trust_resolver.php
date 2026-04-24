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
 * Trust resolver for the MBS-10761 tool-agent.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent;

use local_ai_manager\local\agent\entity\trust_pref;

/**
 * Resolves how a tool-call should be gated for the given user.
 *
 * Returns one of four states (SPEZ §9.4):
 *   - always_ask: normal approval flow
 *   - trusted_session: approval is bypassed for the current PHP session
 *   - trusted_user: approval is bypassed for the user, always
 *   - trusted_global: approval is bypassed tenant-wide
 *
 * Hard guardrails — any of the following forces `always_ask` regardless of
 * trust preferences:
 *   - the tool is not reversible AND affects objects outside the user's data
 *   - the run has consumed `<untrusted_data>` in the last 2 turns
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class trust_resolver {

    /** Resolution: no trust configured or hard guardrail triggered. */
    public const STATE_ALWAYS_ASK = 'always_ask';
    /** Resolution: trust for the current session. */
    public const STATE_TRUSTED_SESSION = 'trusted_session';
    /** Resolution: trust for the user across sessions. */
    public const STATE_TRUSTED_USER = 'trusted_user';
    /** Resolution: trust tenant-wide. */
    public const STATE_TRUSTED_GLOBAL = 'trusted_global';

    /** @var injection_guard used for the "untrusted data in last 2 turns" guardrail. */
    private injection_guard $injectionguard;

    /**
     * Constructor.
     *
     * @param injection_guard|null $injectionguard
     */
    public function __construct(?injection_guard $injectionguard = null) {
        $this->injectionguard = $injectionguard ?? new injection_guard();
    }

    /**
     * Resolve the trust state for a tool invocation.
     *
     * @param tool_definition $tool
     * @param int $userid
     * @param string $sessionkey moodle sesskey() value
     * @param int|null $tenantid
     * @param int|null $runid active agent run (to check recent untrusted-data consumption)
     * @param bool $affectssharedobjects whether affected objects are outside the user's data
     * @return string one of STATE_* constants
     */
    public function resolve(
        tool_definition $tool,
        int $userid,
        string $sessionkey,
        ?int $tenantid,
        ?int $runid,
        bool $affectssharedobjects,
    ): string {
        // Guardrail 1: non-reversible writes on shared objects cannot be auto-trusted.
        if ($affectssharedobjects && !$tool->is_reversible()) {
            return self::STATE_ALWAYS_ASK;
        }

        // Guardrail 2: untrusted data recently consumed.
        if ($runid !== null && $this->injectionguard->run_consumed_untrusted_data($runid)) {
            return self::STATE_ALWAYS_ASK;
        }

        $toolname = $tool->get_name();

        // 1) Global trust (tenant-wide).
        if ($this->has_pref($userid, $toolname, trust_pref::SCOPE_GLOBAL, null, $tenantid)) {
            return self::STATE_TRUSTED_GLOBAL;
        }
        // 2) User-level trust.
        if ($this->has_pref($userid, $toolname, trust_pref::SCOPE_USER, null, $tenantid)) {
            return self::STATE_TRUSTED_USER;
        }
        // 3) Session-level trust.
        if ($sessionkey !== '' && $this->has_pref($userid, $toolname, trust_pref::SCOPE_SESSION, $sessionkey, $tenantid)) {
            return self::STATE_TRUSTED_SESSION;
        }

        return self::STATE_ALWAYS_ASK;
    }

    /**
     * Persist a trust preference.
     *
     * @param int $userid
     * @param string $toolname
     * @param string $scope one of trust_pref::SCOPE_*
     * @param string|null $sessionkey required when $scope === SCOPE_SESSION
     * @param int|null $tenantid
     * @param int $expires 0 = unlimited
     * @return trust_pref
     */
    public function set_trust(
        int $userid,
        string $toolname,
        string $scope,
        ?string $sessionkey,
        ?int $tenantid,
        int $expires = 0,
    ): trust_pref {
        $pref = new trust_pref();
        $pref->set('userid', $userid);
        $pref->set('toolname', $toolname);
        $pref->set('scope', $scope);
        if ($sessionkey !== null) {
            $pref->set('session_id', $sessionkey);
        }
        if ($tenantid !== null) {
            $pref->set('tenantid', $tenantid);
        }
        $pref->set('expires', $expires);
        $pref->create();
        return $pref;
    }

    /**
     * Check whether a trust row exists for the given combination.
     *
     * @param int $userid
     * @param string $toolname
     * @param string $scope
     * @param string|null $sessionkey
     * @param int|null $tenantid
     * @return bool
     */
    private function has_pref(int $userid, string $toolname, string $scope, ?string $sessionkey, ?int $tenantid): bool {
        global $DB;
        $clock = \core\di::get(\core\clock::class);
        $now = $clock->now()->getTimestamp();

        $conditions = [
            'userid' => $userid,
            'toolname' => $toolname,
            'scope' => $scope,
        ];
        $sql = 'userid = :userid AND toolname = :toolname AND scope = :scope ';
        $params = $conditions;

        if ($scope === trust_pref::SCOPE_SESSION) {
            if ($sessionkey === null) {
                return false;
            }
            $sql .= 'AND session_id = :sessionid ';
            $params['sessionid'] = $sessionkey;
        }
        if ($tenantid !== null) {
            $sql .= 'AND (tenantid = :tenantid OR tenantid IS NULL) ';
            $params['tenantid'] = $tenantid;
        }
        $sql .= 'AND (expires = 0 OR expires > :now)';
        $params['now'] = $now;

        return $DB->record_exists_select('local_ai_manager_trust_prefs', $sql, $params);
    }
}

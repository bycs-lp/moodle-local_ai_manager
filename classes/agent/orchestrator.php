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
 * Tool-agent orchestrator (MBS-10761).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent;

use local_ai_manager\agent\exception\tool_parse_exception;
use local_ai_manager\local\agent\entity\agent_run;
use local_ai_manager\local\agent\entity\tool_call;

/**
 * Runs the reason + act loop that drives a tool-capable LLM turn.
 *
 * The orchestrator persists every step as a {@see agent_run} row and one
 * {@see tool_call} per LLM tool invocation so the UI, the approval workflow
 * and the audit log can all operate on the same authoritative state.
 *
 * The loop honours three bounded retries:
 *   - `max_iterations`: total LLM round trips (default 10)
 *   - `max_self_correction`: attempts to recover from a parser failure (hardcoded 3)
 *   - `max_rejection_retries`: attempts to re-call the same tool after a user rejection
 *
 * Concurrency per run is protected by a Redis-backed lock; {@see run()} is the
 * only entry point that issues new LLM calls, {@see resume()} re-enters the
 * loop after the user answered pending approvals.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class orchestrator {

    /** Upper bound on parser self-correction attempts per run. */
    public const MAX_SELF_CORRECTION = 3;

    /** Lock resource prefix used with {@see \core\lock\lock_factory}. */
    public const LOCK_RESOURCE_PREFIX = 'agent_run_';

    /** Lock timeout in seconds. */
    public const LOCK_TIMEOUT = 30;

    /** @var array<string, tool_definition> name => tool. */
    private array $toolmap;

    /** @var trust_resolver */
    private trust_resolver $trustresolver;

    /** @var injection_guard */
    private injection_guard $injectionguard;

    /** @var tool_rate_limiter */
    private tool_rate_limiter $ratelimiter;

    /**
     * Constructor.
     *
     * @param llm_client $client LLM client (real connector adapter in prod, fake in tests)
     * @param tool_protocol $protocol tool-protocol implementation (native / emulated)
     * @param tool_definition[] $availabletools pre-filtered list of tools the user may invoke
     * @param \core\clock $clock injected clock for deterministic tests
     * @param trust_resolver|null $trustresolver
     * @param injection_guard|null $injectionguard
     * @param tool_rate_limiter|null $ratelimiter
     */
    public function __construct(
        private readonly llm_client $client,
        private readonly tool_protocol $protocol,
        array $availabletools,
        private readonly \core\clock $clock,
        ?trust_resolver $trustresolver = null,
        ?injection_guard $injectionguard = null,
        ?tool_rate_limiter $ratelimiter = null,
    ) {
        $this->toolmap = [];
        foreach ($availabletools as $tool) {
            $this->toolmap[$tool->get_name()] = $tool;
        }
        $this->trustresolver = $trustresolver ?? new trust_resolver();
        $this->injectionguard = $injectionguard ?? new injection_guard();
        $this->ratelimiter = $ratelimiter ?? new tool_rate_limiter($this->clock);
    }

    /**
     * Start a new agent run.
     *
     * @param \stdClass $user moodle user record (needs ->id)
     * @param \core\context $context context to run in
     * @param string $userprompt user's natural-language turn
     * @param int $conversationid block_ai_chat conversation id (0 when not linked)
     * @param int|null $tenantid tenant id when tenancy is enabled
     * @param string $component calling component, default 'block_ai_chat'
     * @param int[] $draftitemids draft area ids available to tools
     * @return run_result
     */
    public function run(
        \stdClass $user,
        \core\context $context,
        string $userprompt,
        int $conversationid = 0,
        ?int $tenantid = null,
        string $component = 'block_ai_chat',
        array $draftitemids = [],
    ): run_result {
        // Create the agent_run row first so we can acquire the run-scoped lock.
        $run = new agent_run();
        $run->set('conversationid', $conversationid);
        $run->set('userid', (int) $user->id);
        $run->set('contextid', $context->id);
        $run->set('tenantid', $tenantid);
        $run->set('component', $component);
        $run->set('mode', $this->protocol->get_mode());
        $run->set('connector', $this->client->get_connector_name());
        $run->set('model', $this->client->get_model());
        $run->set('status', agent_run::STATUS_RUNNING);
        $run->set('user_prompt', $userprompt);
        $run->set('started', $this->clock->now()->getTimestamp());
        $run->create();

        $lock = $this->acquire_lock($run->get('id'));
        try {
            $history = $this->load_prior_history($conversationid, (int) $user->id, $component, (int) $context->id);
            $history[] = ['role' => 'user', 'content' => $userprompt];
            return $this->loop($run, $history, $user, $context, $tenantid, $draftitemids);
        } finally {
            if ($lock !== null) {
                $lock->release();
            }
        }
    }

    /**
     * Resume an agent run after the user answered pending approvals.
     *
     * @param int $runid
     * @param \stdClass $user
     * @param \core\context $context
     * @param int[] $draftitemids
     * @return run_result
     */
    public function resume(
        int $runid,
        \stdClass $user,
        \core\context $context,
        array $draftitemids = [],
    ): run_result {
        $run = new agent_run($runid);
        if ($run->get('status') !== agent_run::STATUS_AWAITING_APPROVAL) {
            return $this->run_to_result($run);
        }
        $tenantid = $run->get('tenantid');

        $lock = $this->acquire_lock($runid);
        try {
            // Execute every APPROVED awaiting call, record rejections.
            $pending = $this->load_pending_tool_calls($runid);
            foreach ($pending as $call) {
                $state = $call->get('approval_state');
                if (
                    $state === tool_call::APPROVAL_APPROVED
                    || $state === tool_call::APPROVAL_TRUSTED_SESSION
                    || $state === tool_call::APPROVAL_TRUSTED_GLOBAL
                ) {
                    $this->execute_and_persist($call, $user, $context, $tenantid, $draftitemids);
                }
            }

            // Rebuild history from DB + the newly executed tool_calls.
            $history = $this->rebuild_history($run);

            // Before spending another LLM round trip: abort if the user has rejected the same tool
            // too many times.
            $maxrejretry = max(1, (int) get_config('local_ai_manager', 'agent_rejection_retry_limit') ?: 3);
            if ($this->rejection_limit_reached($run, $maxrejretry)) {
                return $this->fail_run(
                    $run,
                    'rejection_limit_reached',
                    'Tool call rejected too many times; aborting run.',
                    [],
                );
            }

            $run->set('status', agent_run::STATUS_RUNNING);
            $run->save();
            return $this->loop($run, $history, $user, $context, $tenantid, $draftitemids);
        } finally {
            if ($lock !== null) {
                $lock->release();
            }
        }
    }

    /**
     * Core reason + act loop.
     *
     * @param agent_run $run
     * @param array $history
     * @param \stdClass $user
     * @param \core\context $context
     * @param int|null $tenantid
     * @param int[] $draftitemids
     * @return run_result
     */
    private function loop(
        agent_run $run,
        array $history,
        \stdClass $user,
        \core\context $context,
        ?int $tenantid,
        array $draftitemids,
    ): run_result {
        $maxiter = max(1, (int) get_config('local_ai_manager', 'agent_max_iterations') ?: 10);
        $selfcorrections = 0;
        $toolresults = [];

        while (true) {
            $iteration = (int) $run->get('iterations');
            if ($iteration >= $maxiter) {
                $run->set('status', agent_run::STATUS_ABORTED_MAXITER);
                $run->set('finished', $this->clock->now()->getTimestamp());
                $run->save();
                return new run_result(
                    runid: $run->get('id'),
                    status: agent_run::STATUS_ABORTED_MAXITER,
                    iterations: $iteration,
                    tool_results: $toolresults,
                    error_code: 'max_iterations_reached',
                );
            }
            $run->set('iterations', $iteration + 1);
            $run->save();

            // 1. Build payload and ask the LLM.
            $payload = $this->protocol->build_request(
                $this->build_system_prompt($context),
                $this->adapt_history($history),
                $this->export_tool_schemas(),
            );

            try {
                $rawresponse = $this->client->send($payload);
            } catch (\Throwable $e) {
                return $this->fail_run($run, 'llm_transport_error', $e->getMessage(), $toolresults);
            }

            // 2. Parse — with bounded self-correction.
            try {
                $response = $this->protocol->parse_response($rawresponse);
            } catch (tool_parse_exception $e) {
                if ($selfcorrections >= self::MAX_SELF_CORRECTION) {
                    return $this->fail_run(
                        $run,
                        'parse_failed',
                        'LLM response could not be parsed after ' . self::MAX_SELF_CORRECTION . ' attempts: '
                            . $e->getMessage(),
                        $toolresults,
                    );
                }
                $selfcorrections++;
                $history[] = [
                    'role' => 'system',
                    'content' => 'Your previous response could not be parsed (' . $e->getMessage()
                        . '). Respond with a valid tool_call or final action per the documented schema.',
                ];
                continue;
            }

            // 3. Final answer -> done.
            if ($response->action === tool_response::ACTION_FINAL) {
                $run->set('status', agent_run::STATUS_COMPLETED);
                $run->set('final_text', $response->final_text);
                $run->set('finished', $this->clock->now()->getTimestamp());
                $run->save();
                return new run_result(
                    runid: $run->get('id'),
                    status: agent_run::STATUS_COMPLETED,
                    final_text: $response->final_text,
                    iterations: (int) $run->get('iterations'),
                    tool_results: $toolresults,
                );
            }

            // 4. Tool calls — register, gate, maybe execute.
            $assistantmsg = [
                'role' => 'tool_call',
                'content' => '',
                'tool_calls' => $response->calls,
            ];
            $history[] = $assistantmsg;

            $pendingapprovals = [];
            $maxrejretry = max(1, (int) get_config('local_ai_manager', 'agent_rejection_retry_limit') ?: 3);

            foreach ($response->calls as $call) {
                $toolname = (string) ($call['tool'] ?? '');
                $args = (array) ($call['arguments'] ?? []);
                $llmcallid = (string) ($call['id'] ?? '');

                $tool = $this->toolmap[$toolname] ?? null;
                if ($tool === null) {
                    // Unknown tool — surface as synthetic tool_result so LLM can recover.
                    $history[] = $this->tool_result_message(
                        $llmcallid,
                        tool_result::failure('unknown_tool', "Tool '{$toolname}' is not available.")
                    );
                    $toolresults[] = ['toolname' => $toolname, 'ok' => false, 'error' => 'unknown_tool'];
                    continue;
                }

                $callrow = $this->persist_new_tool_call($run, $llmcallid, $toolname, $args);

                // Trust + approval gating.
                $trustedstate = $this->trustresolver->resolve(
                    $tool,
                    (int) $user->id,
                    sesskey(),
                    $tenantid,
                    (int) $run->get('id'),
                    $this->affects_shared_objects($tool, $args),
                );
                $requiresapproval = $tool->requires_approval();
                $autostate = $this->map_trust_to_approval_state($trustedstate);

                if ($requiresapproval && $autostate === tool_call::APPROVAL_AUTO) {
                    // Approval required, not overridden by trust -> pause.
                    $callrow->set('approval_state', tool_call::APPROVAL_AWAITING);
                    $callrow->save();
                    $token = approval_token::instance()->issue(
                        (int) $run->get('id'),
                        (int) $callrow->get('callindex'),
                        (int) $user->id,
                        (string) $callrow->get('args_hash'),
                    );
                    $pendingapprovals[] = [
                        'callid' => (int) $callrow->get('id'),
                        'callindex' => (int) $callrow->get('callindex'),
                        'tool' => $toolname,
                        'args' => $args,
                        'token' => $token,
                        'describe' => $tool->describe_for_user($args),
                        'affected' => $tool->get_affected_objects($args),
                        'dry_run' => $tool->dry_run($args),
                    ];
                    continue;
                }

                // Auto-approve (read-only OR trust overrides).
                $callrow->set('approval_state', $autostate);
                if ($autostate !== tool_call::APPROVAL_AUTO) {
                    $callrow->set('approved_by', (int) $user->id);
                    $callrow->set('approved_at', $this->clock->now()->getTimestamp());
                }
                $callrow->save();

                $result = $this->execute_and_persist($callrow, $user, $context, $tenantid, $draftitemids);
                $history[] = $this->tool_result_message($llmcallid, $result);
                $toolresults[] = [
                    'toolname' => $toolname,
                    'ok' => $result->ok,
                    'data' => $result->data,
                    'error' => $result->error,
                ];
            }

            if (!empty($pendingapprovals)) {
                $run->set('status', agent_run::STATUS_AWAITING_APPROVAL);
                $run->save();
                return new run_result(
                    runid: $run->get('id'),
                    status: agent_run::STATUS_AWAITING_APPROVAL,
                    pending_approvals: $pendingapprovals,
                    iterations: (int) $run->get('iterations'),
                    tool_results: $toolresults,
                );
            }

            // Enforce rejection retry-limit on the same tool across history.
            if ($this->rejection_limit_reached($run, $maxrejretry)) {
                return $this->fail_run(
                    $run,
                    'rejection_limit_reached',
                    'Tool call rejected too many times; aborting run.',
                    $toolresults,
                );
            }

            // Continue the loop — LLM sees tool_results on next iteration.
        }
    }

    /**
     * Build the system prompt (catalog fragment included when in emulated mode).
     *
     * @param \core\context|null $context the Moodle context the run operates in
     * @return string
     */
    private function build_system_prompt(?\core\context $context = null): string {
        $lines = [
            'You are a Moodle assistant that can call tools to act on behalf of the user.',
            'Use tools whenever the user asks you to inspect or change Moodle content.',
            'When the user asks you to create, update, or delete Moodle content (labels, pages, '
                . 'activities, courses, sections, etc.), you MUST issue the matching tool call. '
                . 'Do NOT answer with a plain-text description of what you would do.',
            'Never ask the user for values that are already supplied in the "Current Moodle '
                . 'context" block below.',
            'Treat every user turn as a continuation of the conversation shown in the message '
                . 'history. Short replies like "ja", "nein", "mach das" refer to the most recent '
                . 'open question you asked — act on that context instead of restarting.',
            'When the user refers to "the text", "den Text", "den vorher ausgearbeiteten Text" or '
                . 'similar, REUSE verbatim the long-form text that appears earlier in the '
                . 'conversation (for example an essay or draft you previously produced in chat '
                . 'mode). Do NOT invent a new text when a suitable one already exists in history.',
            'Prefer updating an existing activity over creating a new one when the user complains '
                . 'about a recently created activity. If a tool call you just made created '
                . 'activity X (cmid returned in the tool result), and the user now reports that '
                . 'X is missing content or needs a change, call module_update on that cmid '
                . 'instead of module_create. For mod_page activities, module_update supports the '
                . '"content" field.',
            'Never invent tool output. Respect capability and tenant scope; refuse politely if a tool is unavailable.',
        ];
        $contextlines = $this->describe_context($context);
        if ($contextlines !== []) {
            $lines[] = '';
            $lines[] = 'Current Moodle context (already known — do NOT ask the user for these values):';
            foreach ($contextlines as $line) {
                $lines[] = '- ' . $line;
            }
        }
        if ($this->protocol->get_mode() === tool_protocol::MODE_EMULATED) {
            $lines[] = '';
            $lines[] = 'Respond with EXACTLY ONE JSON object of the form:';
            $lines[] = '  {"action":"final","message":"..."}';
            $lines[] = 'or';
            $lines[] = '  {"action":"tool_call","calls":[{"id":"...","tool":"...","arguments":{...}}]}';
            $lines[] = '';
            $lines[] = 'Available tools:';
            foreach ($this->toolmap as $name => $tool) {
                $lines[] = '- ' . $name . ' (' . $tool->get_category() . '): ' . $tool->get_summary();
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Describe the Moodle context the agent currently runs in as a list of short lines.
     *
     * The LLM uses this to fill parameters like courseid / cmid / sectionid without
     * having to bounce the question back to the user.
     *
     * @param \core\context|null $context
     * @return string[]
     */
    private function describe_context(?\core\context $context): array {
        global $DB;
        if ($context === null) {
            return [];
        }
        $lines = [];
        $lines[] = 'contextid: ' . $context->id . ' (level ' . $context->contextlevel . ')';
        try {
            // Walk parents so block / user / system contexts still resolve the enclosing course or module.
            $coursecontext = $context->get_course_context(false);
            $modulecontext = null;
            foreach ($context->get_parent_contexts(true) as $parent) {
                if ($parent instanceof \core\context\module) {
                    $modulecontext = $parent;
                    break;
                }
            }

            if ($modulecontext !== null) {
                [$course, $cm] = get_course_and_cm_from_cmid($modulecontext->instanceid);
                $lines[] = 'courseid: ' . $course->id;
                $lines[] = 'course fullname: ' . $course->fullname;
                $lines[] = 'course format: ' . $course->format;
                $lines[] = 'cmid: ' . $cm->id;
                $lines[] = 'module: ' . $cm->modname . ' "' . $cm->name . '"';
                if ((int) $cm->sectionnum >= 0) {
                    $lines[] = 'current sectionnum: ' . (int) $cm->sectionnum;
                }
            } else if ($coursecontext instanceof \core\context\course) {
                $course = $DB->get_record('course', ['id' => $coursecontext->instanceid], 'id, fullname, shortname, format');
                if ($course) {
                    $lines[] = 'courseid: ' . $course->id;
                    $lines[] = 'course fullname: ' . $course->fullname;
                    $lines[] = 'course shortname: ' . $course->shortname;
                    $lines[] = 'course format: ' . $course->format;
                    $lines[] = 'default sectionnum when unspecified: 0';
                }
            } else if ($context instanceof \core\context\coursecat) {
                $lines[] = 'course category id: ' . $context->instanceid;
            } else if ($context instanceof \core\context\user) {
                $lines[] = 'user context for userid ' . $context->instanceid;
            } else if ($context instanceof \core\context\system) {
                $lines[] = 'system context (no specific course) - ask the user which course they mean.';
            }
        } catch (\Throwable $e) {
            // Best-effort enrichment; never fail the run because of context lookup.
            $lines[] = 'context resolution error: ' . $e->getMessage();
        }
        return $lines;
    }

    /**
     * Adapt internal history to the provider-facing format.
     *
     * @param array $history
     * @return array
     */
    private function adapt_history(array $history): array {
        $adapter = new tool_message_adapter();
        if ($this->protocol->get_mode() === tool_protocol::MODE_NATIVE) {
            return $adapter->to_native($history);
        }
        return $adapter->to_emulated($history);
    }

    /**
     * Export tool schemas matching the active protocol.
     *
     * @return array
     */
    private function export_tool_schemas(): array {
        $registry = new tool_registry();
        return $registry->export_schemas(array_values($this->toolmap), $this->protocol->get_mode());
    }

    /**
     * Persist a freshly announced tool call (approval state decided later).
     *
     * @param agent_run $run
     * @param string $llmcallid
     * @param string $toolname
     * @param array $args
     * @return tool_call
     */
    private function persist_new_tool_call(
        agent_run $run,
        string $llmcallid,
        string $toolname,
        array $args,
    ): tool_call {
        $callindex = $this->next_callindex((int) $run->get('id'));
        $callrow = new tool_call();
        $callrow->set('runid', (int) $run->get('id'));
        $callrow->set('callindex', $callindex);
        $callrow->set('llm_call_id', $llmcallid !== '' ? $llmcallid : null);
        $callrow->set('toolname', $toolname);
        $callrow->set('args_json', json_encode($args, JSON_UNESCAPED_UNICODE));
        $callrow->set('args_hash', approval_token::hash_args($args));
        $callrow->create();
        return $callrow;
    }

    /**
     * Execute a tool_call row, persist the outcome, return the {@see tool_result}.
     *
     * @param tool_call $callrow
     * @param \stdClass $user
     * @param \core\context $context
     * @param int|null $tenantid
     * @param int[] $draftitemids
     * @return tool_result
     */
    private function execute_and_persist(
        tool_call $callrow,
        \stdClass $user,
        \core\context $context,
        ?int $tenantid,
        array $draftitemids,
    ): tool_result {
        $toolname = (string) $callrow->get('toolname');
        $tool = $this->toolmap[$toolname] ?? null;
        $args = json_decode((string) $callrow->get('args_json'), true) ?: [];

        if ($tool === null) {
            $result = tool_result::failure('unknown_tool', "Tool '{$toolname}' is not available.");
            $this->save_result($callrow, $result, 0);
            return $result;
        }

        $ctx = new execution_context(
            runid: (int) $callrow->get('runid'),
            callid: (int) $callrow->get('id'),
            callindex: (int) $callrow->get('callindex'),
            user: $user,
            context: $context,
            tenantid: $tenantid,
            draftitemids: $draftitemids,
            entity_context: [],
            clock: $this->clock,
        );

        // Paket 3 / §10.5: enforce per-user hourly rate limit before execution.
        try {
            $this->ratelimiter->check_and_increment((int) $user->id, $tool);
        } catch (exception\rate_limit_exceeded_exception $e) {
            $result = tool_result::failure('rate_limit_exceeded', $e->getMessage());
            $this->save_result($callrow, $result, 0);
            return $result;
        }

        // Paket 3 / §9.6: give the tool enough wall-clock time + 5s head-room.
        \core_php_time_limit::raise($tool->get_timeout_seconds() + 5);

        $start = microtime(true);
        try {
            $result = $tool->execute($args, $ctx);
        } catch (\Throwable $e) {
            $result = tool_result::failure('tool_exception', $e->getMessage());
        }
        $duration = (int) round((microtime(true) - $start) * 1000);

        $this->save_result($callrow, $result, $duration);
        return $result;
    }

    /**
     * Persist a {@see tool_result} onto a tool_call row.
     *
     * @param tool_call $callrow
     * @param tool_result $result
     * @param int $durationms
     */
    private function save_result(tool_call $callrow, tool_result $result, int $durationms): void {
        $callrow->set('result_json', json_encode($result->to_array(), JSON_UNESCAPED_UNICODE));
        $callrow->set('duration_ms', $durationms);
        if ($result->undo_payload !== null) {
            $callrow->set('undo_payload', json_encode($result->undo_payload, JSON_UNESCAPED_UNICODE));
        }
        if (!empty($result->affected_objects)) {
            $callrow->set('affected_objects', json_encode($result->affected_objects, JSON_UNESCAPED_UNICODE));
        }
        if (!$result->ok) {
            $callrow->set('error_code', $result->error);
            $callrow->set('error_message', $result->user_message);
        }
        $callrow->save();
    }

    /**
     * Format a tool result as an internal history message.
     *
     * @param string $toolcallid
     * @param tool_result $result
     * @return array
     */
    private function tool_result_message(string $toolcallid, tool_result $result): array {
        return [
            'role' => 'tool_result',
            'tool_call_id' => $toolcallid,
            'content' => json_encode($result->to_array(), JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * Map a {@see trust_resolver} state to a {@see tool_call}::APPROVAL_* state.
     *
     * @param string $state
     * @return string
     */
    private function map_trust_to_approval_state(string $state): string {
        return match ($state) {
            trust_resolver::STATE_TRUSTED_GLOBAL => tool_call::APPROVAL_TRUSTED_GLOBAL,
            trust_resolver::STATE_TRUSTED_USER,
            trust_resolver::STATE_TRUSTED_SESSION => tool_call::APPROVAL_TRUSTED_SESSION,
            default => tool_call::APPROVAL_AUTO,
        };
    }

    /**
     * Best-effort: does the call affect objects the user does not solely own?
     *
     * Honestly answered by the tool's own get_affected_objects(); defaults to true so the
     * guardrail in {@see trust_resolver} errs on the safe side.
     *
     * @param tool_definition $tool
     * @param array $args
     * @return bool
     */
    private function affects_shared_objects(tool_definition $tool, array $args): bool {
        $affected = $tool->get_affected_objects($args);
        // If a tool reports no affected objects at all, we assume it is a pure-read tool.
        return !empty($affected);
    }

    /**
     * Next call index for a given run.
     *
     * @param int $runid
     * @return int
     */
    private function next_callindex(int $runid): int {
        global $DB;
        $max = (int) $DB->get_field_sql(
            'SELECT COALESCE(MAX(callindex), -1) FROM {' . tool_call::TABLE . '} WHERE runid = :runid',
            ['runid' => $runid],
        );
        return $max + 1;
    }

    /**
     * Load prior completed agent runs for this conversation and build a chat history the LLM can see.
     *
     * Only completed runs that produced a final assistant message are included, so the LLM keeps
     * track of multi-turn dialogue (e.g. confirming actions across messages).
     *
     * @param int $conversationid block_ai_chat conversation id; 0 disables history replay
     * @param int $userid the owning user
     * @param string $component calling component
     * @param int $contextid the Moodle context id the run operates in
     * @return array history entries shaped like {role: 'user'|'assistant', content: string}
     */
    private function load_prior_history(int $conversationid, int $userid, string $component, int $contextid = 0): array {
        if ($conversationid <= 0) {
            return [];
        }
        $limit = max(1, (int) (get_config('local_ai_manager', 'agent_history_limit') ?: 10));

        // Build a combined timeline of prior CHAT/AGENT log entries and completed tool-agent runs
        // so the LLM sees everything that happened before — including essays or drafts the user
        // produced in normal chat mode that they now want the agent to act on.
        $timeline = [];

        $runs = agent_run::get_records(
            [
                'conversationid' => $conversationid,
                'userid' => $userid,
                'component' => $component,
                'status' => agent_run::STATUS_COMPLETED,
            ],
            'id',
            'ASC',
        );
        foreach ($runs as $prior) {
            $userprompt = (string) $prior->get('user_prompt');
            $finaltext = (string) $prior->get('final_text');
            if ($userprompt === '' || $finaltext === '') {
                continue;
            }
            $timeline[] = [
                'sortkey' => (int) $prior->get('started'),
                'user' => $userprompt,
                'assistant' => $finaltext,
            ];
        }

        $logentries = \local_ai_manager\ai_manager_utils::get_log_entries(
            $component,
            $contextid,
            $userid,
            $conversationid,
            false,
            'id,prompttext,promptcompletion,timecreated,contextid',
            ['chat', 'agent'],
        );
        foreach ($logentries as $entry) {
            $userprompt = (string) ($entry->prompttext ?? '');
            $aitext = (string) ($entry->promptcompletion ?? '');
            if ($userprompt === '' && $aitext === '') {
                continue;
            }
            $timeline[] = [
                'sortkey' => (int) $entry->timecreated,
                'user' => $userprompt,
                'assistant' => $aitext,
            ];
        }

        usort($timeline, static fn($a, $b) => $a['sortkey'] <=> $b['sortkey']);
        if (count($timeline) > $limit) {
            $timeline = array_slice($timeline, -$limit);
        }
        $history = [];
        foreach ($timeline as $item) {
            if ($item['user'] !== '') {
                $history[] = ['role' => 'user', 'content' => $item['user']];
            }
            if ($item['assistant'] !== '') {
                $history[] = ['role' => 'assistant', 'content' => $item['assistant']];
            }
        }
        return $history;
    }

    /**
     * Load tool_calls currently in AWAITING/APPROVED/REJECTED state (not yet reflected in history).
     *
     * @param int $runid
     * @return tool_call[]
     */
    private function load_pending_tool_calls(int $runid): array {
        $all = tool_call::get_records(['runid' => $runid], 'callindex', 'ASC');
        $result = [];
        foreach ($all as $row) {
            $state = $row->get('approval_state');
            if (
                $state === tool_call::APPROVAL_AWAITING
                || $state === tool_call::APPROVAL_APPROVED
                || $state === tool_call::APPROVAL_REJECTED
                || $state === tool_call::APPROVAL_EXPIRED
            ) {
                if ($row->get('result_json') === null) {
                    $result[] = $row;
                }
            }
        }
        return $result;
    }

    /**
     * Rebuild the internal history from persisted rows.
     *
     * @param agent_run $run
     * @return array
     */
    private function rebuild_history(agent_run $run): array {
        $history = [['role' => 'user', 'content' => (string) $run->get('user_prompt')]];
        $calls = tool_call::get_records(['runid' => (int) $run->get('id')], 'callindex', 'ASC');

        $pendinggroup = [];
        foreach ($calls as $row) {
            $args = json_decode((string) $row->get('args_json'), true) ?: [];
            $pendinggroup[] = [
                'id' => (string) ($row->get('llm_call_id') ?: 'call_' . $row->get('callindex')),
                'tool' => (string) $row->get('toolname'),
                'arguments' => $args,
            ];
        }
        if (!empty($pendinggroup)) {
            $history[] = [
                'role' => 'tool_call',
                'content' => '',
                'tool_calls' => $pendinggroup,
            ];
            foreach ($calls as $row) {
                if ($row->get('result_json') === null) {
                    // Rejection or expired -> synthesise a failure result so the LLM sees the outcome.
                    $result = tool_result::failure(
                        $row->get('approval_state') === tool_call::APPROVAL_REJECTED
                            ? 'rejected_by_user'
                            : 'approval_expired',
                        'User did not approve this tool call.',
                    );
                } else {
                    $decoded = json_decode((string) $row->get('result_json'), true) ?: [];
                    $result = new tool_result(
                        ok: (bool) ($decoded['ok'] ?? false),
                        data: $decoded['data'] ?? null,
                        error: $decoded['error'] ?? null,
                        user_message: $decoded['user_message'] ?? null,
                        affected_objects: (array) ($decoded['affected_objects'] ?? []),
                        undo_payload: $decoded['undo_payload'] ?? null,
                        metrics: (array) ($decoded['metrics'] ?? []),
                    );
                }
                $history[] = $this->tool_result_message(
                    (string) ($row->get('llm_call_id') ?: 'call_' . $row->get('callindex')),
                    $result,
                );
            }
        }
        return $history;
    }

    /**
     * Check whether any single tool name has been rejected more times than configured.
     *
     * @param agent_run $run
     * @param int $limit
     * @return bool
     */
    private function rejection_limit_reached(agent_run $run, int $limit): bool {
        $calls = tool_call::get_records(['runid' => (int) $run->get('id')]);
        $rejectionsbytool = [];
        foreach ($calls as $row) {
            if ($row->get('approval_state') === tool_call::APPROVAL_REJECTED) {
                $toolname = (string) $row->get('toolname');
                $rejectionsbytool[$toolname] = ($rejectionsbytool[$toolname] ?? 0) + 1;
            }
        }
        foreach ($rejectionsbytool as $count) {
            if ($count >= $limit) {
                return true;
            }
        }
        return false;
    }

    /**
     * Mark a run as FAILED and build the matching run_result.
     *
     * @param agent_run $run
     * @param string $code
     * @param string $message
     * @param array $toolresults
     * @return run_result
     */
    private function fail_run(agent_run $run, string $code, string $message, array $toolresults): run_result {
        $run->set('status', agent_run::STATUS_FAILED);
        $run->set('error_code', $code);
        $run->set('error_message', $message);
        $run->set('finished', $this->clock->now()->getTimestamp());
        $run->save();
        return new run_result(
            runid: (int) $run->get('id'),
            status: agent_run::STATUS_FAILED,
            iterations: (int) $run->get('iterations'),
            tool_results: $toolresults,
            error_code: $code,
            error_message: $message,
        );
    }

    /**
     * Build a minimal run_result from an already-terminal agent_run (for re-entrant resume).
     *
     * @param agent_run $run
     * @return run_result
     */
    private function run_to_result(agent_run $run): run_result {
        return new run_result(
            runid: (int) $run->get('id'),
            status: (string) $run->get('status'),
            iterations: (int) $run->get('iterations'),
            error_code: $run->get('error_code'),
            error_message: $run->get('error_message'),
        );
    }

    /**
     * Acquire the per-run Redis lock. Returns null when the locking subsystem is unavailable
     * (e.g. pure unit tests without Redis) — the orchestrator then relies on DB row-level
     * isolation only.
     *
     * @param int $runid
     * @return \core\lock\lock|null
     */
    private function acquire_lock(int $runid): ?\core\lock\lock {
        try {
            $factory = \core\lock\lock_config::get_lock_factory('local_ai_manager_agent_run');
        } catch (\Throwable $e) {
            return null;
        }
        $lock = $factory->get_lock(self::LOCK_RESOURCE_PREFIX . $runid, self::LOCK_TIMEOUT);
        return $lock === false ? null : $lock;
    }
}

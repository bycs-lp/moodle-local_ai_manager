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
 * Tool registry for the MBS-10761 tool-agent.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent;

/**
 * Registry that discovers and filters available agent tools.
 *
 * Discovery sources (in order):
 *   1. Class scan under local_ai_manager/classes/agent/tools/&lt;category&gt;/*.php
 *   2. WS-backed adapters declared in classes/agent/ws_tools.php
 *   3. 3rd-party tools declared via db/agenttools.php in any plugin
 *
 * Results of the expensive discovery step are cached in MUC
 * `cache::make('local_ai_manager', 'agent_tools')` and invalidated on
 * plugin upgrade / purge_caches.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_registry {

    /** Cache key used inside the 'agent_tools' MUC definition. */
    public const CACHE_KEY_CATALOG = 'catalog';

    /** @var array<string, tool_definition>|null in-process cache of instantiated tools. */
    private ?array $tools = null;

    /**
     * Warm the underlying MUC cache. Idempotent.
     *
     * @return void
     */
    public function warm_cache(): void {
        $this->tools = null;
        $cache = \cache::make('local_ai_manager', 'agent_tools');
        $cache->delete(self::CACHE_KEY_CATALOG);
        $this->load_tools();
    }

    /**
     * Returns the list of tools the given user may invoke in the given context.
     *
     * Filter order (fail-closed):
     *   1. Tool disabled site-wide (admin UI) -> out
     *   2. Explicit allowlist mismatch -> out
     *   3. Required capabilities missing in $ctx -> out
     *   4. is_available_for() returns false -> out
     *
     * @param \stdClass $user
     * @param \core\context $ctx
     * @param string[]|null $allowlist optional explicit allowlist of tool names
     * @return tool_definition[] numerically indexed
     */
    public function get_tools_for(\stdClass $user, \core\context $ctx, ?array $allowlist = null): array {
        $result = [];
        foreach ($this->load_tools() as $name => $tool) {
            if ($allowlist !== null && !in_array($name, $allowlist, true)) {
                continue;
            }
            if (!$this->is_enabled($name)) {
                continue;
            }
            $missing = false;
            foreach ($tool->get_required_capabilities() as $cap) {
                if (!has_capability($cap, $ctx, $user)) {
                    $missing = true;
                    break;
                }
            }
            if ($missing) {
                continue;
            }
            if (!$tool->is_available_for($ctx, (int) $user->id)) {
                continue;
            }
            $result[] = $tool;
        }
        return $result;
    }

    /**
     * Look up a tool by its machine name.
     *
     * @param string $name
     * @return tool_definition
     * @throws \coding_exception if no tool is registered for $name.
     */
    public function get_by_name(string $name): tool_definition {
        $all = $this->load_tools();
        if (!isset($all[$name])) {
            throw new \coding_exception("Unknown agent tool: {$name}");
        }
        return $all[$name];
    }

    /**
     * Full tool catalog (for Admin UI, doc-quality scoring).
     *
     * @return tool_definition[]
     */
    public function get_all(): array {
        return array_values($this->load_tools());
    }

    /**
     * Export tools as a provider-specific schema array.
     *
     * @param tool_definition[] $tools
     * @param string $mode 'native' or 'emulated'
     * @return array
     */
    public function export_schemas(array $tools, string $mode): array {
        $out = [];
        foreach ($tools as $tool) {
            if ($mode === 'native') {
                $out[] = [
                    'type' => 'function',
                    'function' => [
                        'name' => $tool->get_name(),
                        'description' => $tool->get_description(),
                        'parameters' => $tool->get_parameters_schema(),
                    ],
                ];
            } else {
                // Emulated: markdown catalog fragment per tool.
                $out[] = [
                    'name' => $tool->get_name(),
                    'category' => $tool->get_category(),
                    'description' => $tool->get_description(),
                    'parameters' => $tool->get_parameters_schema(),
                    'requires_approval' => $tool->requires_approval(),
                ];
            }
        }
        return $out;
    }

    /**
     * Validate a tool's metadata against the documentation contract.
     *
     * Returns a list of warnings. Hard-fail cases raise a coding_exception.
     *
     * @param tool_definition $tool
     * @return string[] warnings
     * @throws \coding_exception on hard failures
     */
    public function validate_metadata(tool_definition $tool): array {
        $warnings = [];
        $name = $tool->get_name();

        if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            throw new \coding_exception("Tool name '{$name}' must be snake_case starting with a letter.");
        }

        $desc = $tool->get_description();
        if (mb_strlen($desc) < 200) {
            $warnings[] = "Tool '{$name}': description shorter than 200 chars";
        }
        if (!str_contains(strtolower($desc), 'use this tool when')) {
            $warnings[] = "Tool '{$name}': description missing 'Use this tool when' section";
        }
        if (!str_contains(strtolower($desc), 'do not use')) {
            $warnings[] = "Tool '{$name}': description missing 'Do NOT use' section";
        }

        $schema = $tool->get_parameters_schema();
        if (!isset($schema['properties']) || !is_array($schema['properties'])) {
            throw new \coding_exception("Tool '{$name}': parameters schema must have a 'properties' object.");
        }
        foreach ($schema['properties'] as $prop => $def) {
            if (empty($def['description'])) {
                $warnings[] = "Tool '{$name}': parameter '{$prop}' missing description";
            }
        }

        if ($tool->is_reversible() && $tool->build_undo_payload([], tool_result::success(null)) === null) {
            // Smoke test only — full check happens at runtime with real args.
            $warnings[] = "Tool '{$name}': marked reversible but build_undo_payload() returned null for smoke test";
        }

        return $warnings;
    }

    /**
     * Discover and instantiate all registered tools.
     *
     * @return array<string, tool_definition>
     */
    private function load_tools(): array {
        if ($this->tools !== null) {
            return $this->tools;
        }

        $cache = \cache::make('local_ai_manager', 'agent_tools');
        $cached = $cache->get(self::CACHE_KEY_CATALOG);
        if (is_array($cached) && !empty($cached)) {
            $loaded = [];
            foreach ($cached as $classname) {
                if (class_exists($classname)) {
                    $tool = new $classname();
                    if ($tool instanceof tool_definition) {
                        $loaded[$tool->get_name()] = $tool;
                    }
                }
            }
            $this->tools = $loaded;
            return $this->tools;
        }

        $discovered = [];

        // 1. Core class scan under classes/agent/tools/<category>/*.php.
        $discovered = array_merge($discovered, $this->discover_core_tools());

        // 2. WS-backed adapters declared in classes/agent/ws_tools.php.
        $discovered = array_merge($discovered, $this->discover_ws_tools());

        // 3. 3rd-party plugins via db/agenttools.php.
        $discovered = array_merge($discovered, $this->discover_thirdparty_tools());

        // Cache class names only — instances are not serialisable.
        $classnames = [];
        foreach ($discovered as $tool) {
            $classnames[] = get_class($tool);
        }
        $cache->set(self::CACHE_KEY_CATALOG, $classnames);

        $byname = [];
        foreach ($discovered as $tool) {
            $byname[$tool->get_name()] = $tool;
        }
        $this->tools = $byname;
        return $this->tools;
    }

    /**
     * Scan classes/agent/tools/<category>/*.php for tool_definition implementations.
     *
     * @return tool_definition[]
     */
    private function discover_core_tools(): array {
        global $CFG;
        $result = [];
        $base = $CFG->dirroot . '/local/ai_manager/classes/agent/tools';
        if (!is_dir($base)) {
            return $result;
        }
        foreach (glob($base . '/*', GLOB_ONLYDIR) as $categorydir) {
            $category = basename($categorydir);
            foreach (glob($categorydir . '/*.php') as $phpfile) {
                $classname = '\\local_ai_manager\\agent\\tools\\' . $category . '\\' . basename($phpfile, '.php');
                if (class_exists($classname)) {
                    $tool = new $classname();
                    if ($tool instanceof tool_definition) {
                        $result[] = $tool;
                    }
                }
            }
        }
        return $result;
    }

    /**
     * Instantiate WS-backed tool adapters declared in classes/agent/ws_tools.php.
     *
     * @return tool_definition[]
     */
    private function discover_ws_tools(): array {
        global $CFG;
        $file = $CFG->dirroot . '/local/ai_manager/classes/agent/ws_tools.php';
        if (!is_readable($file)) {
            return [];
        }
        $wstools = [];
        include($file);
        $result = [];
        foreach ($wstools as $entry) {
            if (!empty($entry['enabled']) && !empty($entry['wsfunction'])) {
                $result[] = new ws_backed_tool_definition($entry);
            }
        }
        return $result;
    }

    /**
     * Discover 3rd-party tools declared via db/agenttools.php in installed plugins.
     *
     * @return tool_definition[]
     */
    private function discover_thirdparty_tools(): array {
        $result = [];
        $plugintypes = \core_component::get_plugin_types();
        foreach ($plugintypes as $type => $_dir) {
            foreach (\core_component::get_plugin_list($type) as $plugin => $plugindir) {
                $file = $plugindir . '/db/agenttools.php';
                if (!is_readable($file)) {
                    continue;
                }
                $agenttools = [];
                include($file);
                if (!is_array($agenttools)) {
                    continue;
                }
                foreach ($agenttools as $entry) {
                    if (isset($entry['enabled']) && !$entry['enabled']) {
                        continue;
                    }
                    if (empty($entry['class']) || !class_exists($entry['class'])) {
                        debugging("agenttools.php in {$type}_{$plugin}: class missing", DEBUG_DEVELOPER);
                        continue;
                    }
                    $tool = new $entry['class']();
                    if (!($tool instanceof tool_definition)) {
                        debugging("agenttools.php in {$type}_{$plugin}: "
                            . $entry['class'] . " does not implement tool_definition", DEBUG_DEVELOPER);
                        continue;
                    }
                    $expectedprefix = $type . '_' . $plugin . '_';
                    if (!str_starts_with($tool->get_name(), $expectedprefix) && $type !== 'local') {
                        debugging("agenttools.php in {$type}_{$plugin}: tool name '"
                            . $tool->get_name() . "' missing frankenstyle prefix '{$expectedprefix}'",
                            DEBUG_DEVELOPER);
                    }
                    $result[] = $tool;
                }
            }
        }
        return $result;
    }

    /**
     * Check whether a tool is enabled via admin configuration.
     *
     * Tools are enabled by default; the Baustein 8 override UI stores per-tenant
     * `enabled_for_tenant` flags. Until that UI ships, this is effectively a passthrough.
     *
     * @param string $toolname
     * @return bool
     */
    private function is_enabled(string $toolname): bool {
        // MBS-10761 / Baustein 8: The override table will provide per-tenant disable flags.
        $disabledcsv = (string) get_config('local_ai_manager', 'agent_disabled_tools');
        if ($disabledcsv === '') {
            return true;
        }
        $disabled = array_map('trim', explode(',', $disabledcsv));
        return !in_array($toolname, $disabled, true);
    }
}

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
 * Tool-description resolver (MBS-10761, Baustein 2 skeleton).
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_manager\agent;

/**
 * Resolve the effective LLM description of a tool by walking the fallback chain.
 *
 * Fallback order (SPEZ §19 / KONZEPT §3.2.5):
 *   1. Tenant override (DB, Baustein 8)
 *   2. Site override (DB, Baustein 8)
 *   3. Hardcoded default from tool_definition::get_description()
 *
 * Additive fields (appended independently, wrapped in &lt;tenant_additions&gt; tags):
 *   - example_appendix
 *   - glossary (rendered as "Terminology:" section)
 *
 * Until Baustein 8 ships the DB-backed override storage, this resolver only
 * returns the hardcoded default. The interface is designed so Baustein 8 can
 * plug in the override lookup without changing callers.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_description_resolver {

    /**
     * Resolve the effective description for a tool and tenant.
     *
     * @param tool_definition $tool
     * @param int|null $tenantid null for site-level resolution
     * @return string effective description
     */
    public function resolve(tool_definition $tool, ?int $tenantid = null): string {
        $base = $tool->get_description();

        $tenantoverride = $this->load_override_from_db($tool->get_name(), $tenantid);
        $siteoverride = $tenantid === null ? null : $this->load_override_from_db($tool->get_name(), null);

        $description = $tenantoverride['description_override']
            ?? $siteoverride['description_override']
            ?? $base;

        $appendix = [];
        foreach ([$siteoverride, $tenantoverride] as $override) {
            if ($override === null) {
                continue;
            }
            if (!empty($override['example_appendix'])) {
                $appendix[] = "<tenant_additions>\nAdditional examples (site-specific):\n"
                    . htmlspecialchars($override['example_appendix'], ENT_XML1 | ENT_QUOTES, 'UTF-8')
                    . "\n</tenant_additions>";
            }
            if (!empty($override['glossary_json'])) {
                $glossary = json_decode($override['glossary_json'], true);
                if (is_array($glossary)) {
                    $items = [];
                    foreach ($glossary as $term => $meaning) {
                        $items[] = '- ' . $term . ' = ' . $meaning;
                    }
                    $appendix[] = "<tenant_additions>\nTerminology:\n"
                        . htmlspecialchars(implode("\n", $items), ENT_XML1 | ENT_QUOTES, 'UTF-8')
                        . "\n</tenant_additions>";
                }
            }
        }

        if (empty($appendix)) {
            return $description;
        }
        return $description . "\n\n" . implode("\n\n", $appendix);
    }

    /**
     * Load an override row from the DB.
     *
     * The override table (`local_ai_manager_tool_overrides`) is created in Baustein 8.
     * This helper returns null while the table does not yet exist so callers can use
     * {@see resolve()} unconditionally.
     *
     * @param string $toolname
     * @param int|null $tenantid null for site-level row
     * @return array|null associative row or null if none
     */
    private function load_override_from_db(string $toolname, ?int $tenantid): ?array {
        global $DB;
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('local_ai_manager_tool_overrides')) {
            return null;
        }
        if ($tenantid === null) {
            // Site-level row: tenantid IS NULL.
            $record = $DB->get_record_select(
                'local_ai_manager_tool_overrides',
                'toolname = :toolname AND tenantid IS NULL AND enabled = 1',
                ['toolname' => $toolname]
            );
        } else {
            $record = $DB->get_record(
                'local_ai_manager_tool_overrides',
                ['toolname' => $toolname, 'tenantid' => $tenantid, 'enabled' => 1]
            );
        }
        if (!$record) {
            return null;
        }
        return [
            'description_override' => $record->llm_description_override,
            'example_appendix' => $record->example_appendix,
            'glossary_json' => $record->glossary_json,
        ];
    }
}

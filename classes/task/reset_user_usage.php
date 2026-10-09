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

namespace local_ai_manager\task;

use local_ai_manager\local\config_manager;
use local_ai_manager\local\tenant_factory;
use local_ai_manager\local\tenant;

/**
 * Cleanup task for cleaning up broken tasks which left locks and entries behind in redis and the database.
 *
 * Care: If all scheduled task locks already have been burned, this task will not run, so you will have to fix this by
 * running cli/cleanup_broken_task_entries.php to unlock the tasks again.
 *
 * @package   local_ai_manager
 * @copyright 2024 ISB Bayern
 * @author    Philipp Memmel
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reset_user_usage extends \core\task\scheduled_task {
    /** @var int Maximum number of records which are being updated by a single UPDATE statement. */
    public const BATCH_SIZE = 500;

    /** @var \core\clock the clock object */
    private \core\clock $clock;

    /**
     * Create the task object.
     */
    public function __construct() {
        $this->clock = \core\di::get(\core\clock::class);
    }

    #[\Override]
    public function get_name(): string {
        return get_string('resetuserusagetask', 'local_ai_manager');
    }

    #[\Override]
    public function execute(): void {
        global $DB;
        $tenantfield = get_config('local_ai_manager', 'tenantcolumn');
        $tenants = $DB->get_fieldset_sql("SELECT DISTINCT " . $tenantfield
                . " FROM {local_ai_manager_userusage} uu LEFT JOIN {user} u ON uu.userid = u.id");
        if (empty($tenants)) {
            // Just in the rare case of an empty table.
            mtrace('No entries found. Exiting.');
            return;
        }

        $tenantfactory = \core\di::get(tenant_factory::class);
        $now = $this->clock->time();
        foreach ($tenants as $tenantidentifier) {
            // We intentionally do not use \core\di here, because we need to reset the objects for each tenant.
            $tenant = new tenant($tenantidentifier);
            $tenantfactory->set($tenant);
            $configmanager = new config_manager($tenantfactory);
            // A record has to be reset if "$now - lastreset > period", which equals "lastreset < $now - period".
            $threshold = $now - $configmanager->get_max_requests_period();

            // Only fetch the ids of the records which really need to be reset, so we do not write unchanged rows.
            $sql = "SELECT uu.id
                      FROM {local_ai_manager_userusage} uu
                      JOIN {user} u ON uu.userid = u.id
                     WHERE " . $tenantfield . " = :tenantidentifier
                           AND (uu.lastreset IS NULL OR uu.lastreset < :threshold)";
            $ids = $DB->get_fieldset_sql($sql, ['tenantidentifier' => $tenantidentifier, 'threshold' => $threshold]);
            if (empty($ids)) {
                continue;
            }

            $this->reset_usage_records($ids, $now);
            mtrace('Successfully reset user usage of tenant ' . $tenantidentifier . ' (' . count($ids) . ' records)');
        }
    }

    /**
     * Resets the usage of the given records by using batched bulk updates.
     *
     * Each batch is being executed as a separate UPDATE statement (and thus a separate transaction/writeset).
     *
     * @param array $ids the ids of the local_ai_manager_userusage records to reset
     * @param int $time the timestamp to store as last reset time
     */
    protected function reset_usage_records(array $ids, int $time): void {
        global $DB;
        foreach (array_chunk($ids, self::BATCH_SIZE) as $chunk) {
            [$insql, $inparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'uuid');
            $sql = "UPDATE {local_ai_manager_userusage}
                       SET currentusage = 0, lastreset = :lastreset
                     WHERE id " . $insql;
            $DB->execute($sql, array_merge($inparams, ['lastreset' => $time]));
        }
    }
}

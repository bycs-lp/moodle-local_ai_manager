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

namespace aitool_telli\local;

/**
 * Cleanup class for Telli consumption data.
 *
 * Re-processes existing consumption data to apply epsilon-based float
 * comparison for reset detection, removing false positive aggregate records.
 *
 * @package    aitool_telli
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_consumption_data {
    /**
     * Epsilon tolerance for float comparison in cents.
     *
     * Must match the value in get_consumption task.
     */
    private const EPSILON_TOLERANCE = 0.01;

    /** @var bool Dry run mode flag */
    private $dryrun;

    /** @var array Statistics tracking */
    private $stats = [
        'total_records' => 0,
        'current_records' => 0,
        'aggregate_records' => 0,
        'false_positives_removed' => 0,
        'new_aggregates_created' => 0,
        'records_preserved' => 0,
    ];

    /** @var bool CLI mode flag */
    private $climode;

    /**
     * Constructor.
     *
     * @param bool $dryrun Whether to run in dry-run mode
     * @param bool $climode Whether running in CLI mode (for output)
     */
    public function __construct(bool $dryrun = false, bool $climode = true) {
        $this->dryrun = $dryrun;
        $this->climode = $climode;
    }

    /**
     * Execute the cleanup process.
     *
     * @return bool Success status
     */
    public function execute(): bool {
        global $DB;

        $this->output_heading('Telli Consumption Data Cleanup');

        if ($this->dryrun) {
            $this->output_line('Running in DRY-RUN mode - no changes will be made');
            $this->output_line('');
        }

        // Get all existing records sorted by time and ID.
        $this->output_line('Loading existing consumption records...');
        $records = $DB->get_records('aitool_telli_consumption', null, 'timecreated ASC, id ASC');

        if (empty($records)) {
            $this->output_line('No consumption records found. Nothing to clean up.');
            return true;
        }

        $this->stats['total_records'] = count($records);
        $this->output_line("Found {$this->stats['total_records']} records");
        $this->output_line('');

        // Separate current and aggregate records.
        $currentrecords = [];
        $aggregaterecords = [];

        foreach ($records as $record) {
            if ($record->type === 'current') {
                $currentrecords[] = $record;
                $this->stats['current_records']++;
            } else if ($record->type === 'aggregate') {
                $aggregaterecords[] = $record;
                $this->stats['aggregate_records']++;
            }
        }

        $this->output_line("Current records: {$this->stats['current_records']}");
        $this->output_line("Aggregate records: {$this->stats['aggregate_records']}");
        $this->output_line('');

        // Process records and build new dataset.
        $this->output_line('Re-evaluating records with epsilon-based comparison...');
        $newrecords = $this->process_records($currentrecords);

        // Display statistics.
        $this->output_line('');
        $this->display_statistics();

        if ($this->dryrun) {
            $this->output_line('');
            $this->output_line('DRY-RUN: No changes were made to the database.');
            return true;
        }

        // Apply changes to database.
        $this->output_line('');
        $this->output_line('Applying changes to database...');

        try {
            $transaction = $DB->start_delegated_transaction();

            // Insert new records.
            foreach ($newrecords as $record) {
                $DB->insert_record('aitool_telli_consumption', $record, false);
            }

            // Delete old records.
            $oldids = array_column($records, 'id');
            if (!empty($oldids)) {
                [$insql, $params] = $DB->get_in_or_equal($oldids);
                $DB->delete_records_select('aitool_telli_consumption', "id $insql", $params);
            }

            $transaction->allow_commit();

            $this->output_line('Database updated successfully.');
            return true;
        } catch (\Exception $e) {
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            $this->output_error('Error updating database: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Process current records and generate new dataset with corrected aggregates.
     *
     * @param array $currentrecords Array of current consumption records
     * @return array New records to insert
     */
    private function process_records(array $currentrecords): array {
        $newrecords = [];
        $previousvalue = null;
        $previousrecord = null;
        $oldidstotrack = [];

        foreach ($currentrecords as $record) {
            $currentvalue = (float)$record->value;

            // Check if this is a reset (consumption decreased significantly).
            // Use epsilon with small tolerance to account for floating-point precision.
            $diff = $previousvalue - $currentvalue;
            if (
                $previousvalue !== null && $previousrecord !== null
                    && $diff > (self::EPSILON_TOLERANCE + 0.0001)
            ) {
                // This is a real reset - create aggregate record.
                $aggregaterecord = new \stdClass();
                $aggregaterecord->type = 'aggregate';
                $aggregaterecord->value = $previousvalue;
                $aggregaterecord->timecreated = $previousrecord->timecreated;

                $newrecords[] = $aggregaterecord;
                $this->stats['new_aggregates_created']++;

                $this->output_line(sprintf(
                    '  → Reset detected: %.6f → %.6f (diff: %.6f) at %s',
                    $previousvalue,
                    $currentvalue,
                    $diff,
                    userdate($previousrecord->timecreated, '%Y-%m-%d %H:%M:%S')
                ));
            } else if (
                $previousvalue !== null && $previousrecord !== null && $diff > 0
                    && $diff <= (self::EPSILON_TOLERANCE + 0.0001)
            ) {
                // This is within epsilon - false positive removed.
                $this->stats['false_positives_removed']++;

                $this->output_line(sprintf(
                    '  ✓ False positive prevented: %.6f → %.6f (diff: %.6f)',
                    $previousvalue,
                    $currentvalue,
                    $diff
                ));
            }

            // Insert current record.
            $newcurrentrecord = new \stdClass();
            $newcurrentrecord->type = 'current';
            $newcurrentrecord->value = $currentvalue;
            $newcurrentrecord->timecreated = $record->timecreated;

            $newrecords[] = $newcurrentrecord;
            $this->stats['records_preserved']++;

            // Track for next iteration.
            $previousvalue = $currentvalue;
            $previousrecord = $record;
            $oldidstotrack[] = $record->id;
        }

        return $newrecords;
    }

    /**
     * Display cleanup statistics.
     */
    private function display_statistics(): void {
        $this->output_heading('Cleanup Statistics');

        $this->output_line(sprintf('Total records processed:       %d', $this->stats['total_records']));
        $this->output_line(sprintf('  - Current records:           %d', $this->stats['current_records']));
        $this->output_line(sprintf('  - Old aggregate records:     %d', $this->stats['aggregate_records']));
        $this->output_line('');
        $this->output_line(sprintf('New aggregates created:        %d', $this->stats['new_aggregates_created']));
        $this->output_line(sprintf('False positives removed:       %d', $this->stats['false_positives_removed']));
        $this->output_line(sprintf('Current records preserved:     %d', $this->stats['records_preserved']));
        $this->output_line('');

        $newaggregatetotal = $this->stats['new_aggregates_created'];
        $oldaggregatetotal = $this->stats['aggregate_records'];
        $aggregatediff = $newaggregatetotal - $oldaggregatetotal;

        if ($aggregatediff < 0) {
            $this->output_line(sprintf(
                'Net aggregate reduction:       %d (removed %d false positives)',
                abs($aggregatediff),
                abs($aggregatediff)
            ));
        } else if ($aggregatediff > 0) {
            $this->output_line(sprintf(
                'Net aggregate increase:        %d (more accurate detection)',
                $aggregatediff
            ));
        } else {
            $this->output_line('Net aggregate change:          0 (no false positives found)');
        }
    }

    /**
     * Output a heading.
     *
     * @param string $text Heading text
     */
    private function output_heading(string $text): void {
        if ($this->climode) {
            cli_heading($text);
        }
    }

    /**
     * Output a line of text.
     *
     * @param string $text Line text
     */
    private function output_line(string $text): void {
        if ($this->climode) {
            cli_writeln($text);
        }
    }

    /**
     * Output an error message.
     *
     * @param string $text Error text
     */
    private function output_error(string $text): void {
        if ($this->climode) {
            cli_error($text);
        }
    }

    /**
     * Get statistics from the last execution.
     *
     * @return array Statistics array
     */
    public function get_stats(): array {
        return $this->stats;
    }
}

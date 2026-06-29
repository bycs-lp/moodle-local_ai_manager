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
 * A scheduled task for RAG content indexing.
 *
 * @package    aipurpose_rag
 * @copyright  2025 University Of Strathclyde <learning-technologies@strath.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aipurpose_rag\task;

defined('MOODLE_INTERNAL') || die();

/**
 * A scheduled task for RAG content indexing.
 *
 * This task indexes content from across the site using the global search system
 * and passes it to the RAG purpose with the 'store' action. The stored content
 * can then be used for retrieval during AI interactions.
 *
 * @package    aipurpose_rag
 * @copyright  2025 University Of Strathclyde <learning-technologies@strath.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class indexer_task extends \core\task\scheduled_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('indexertask', 'aipurpose_rag');
    }

    /**
     * Run the RAG indexer task.
     * 
     * This method implements the core logic from the CLI indexer script,
     * adapted for use in the scheduled task system.
     */
    public function execute() {
        global $CFG;

        mtrace('Starting RAG indexer scheduled task...');

        // Get the global search manager
        $globalsearch = \core_search\manager::instance();
        if (!$globalsearch) {
            mtrace('Global search manager not available. Skipping task.');
            return;
        }

        // Get our RAG indexer manager
        $ragindexer = new \aipurpose_rag\indexer_manager(
            new \stdClass(), // Placeholder for AI manager - will be properly initialized in indexer_manager
            $globalsearch
        );

        if (!$ragindexer) {
            mtrace('RAG indexer manager not available. Skipping task.');
            return;
        }

        // Setup progress tracing for task output
        $trace = new \core\progress\none();

        // Get configuration for task behavior
        $timelimit = $this->get_time_limit();
        $fullreindex = $this->should_run_full_reindex();

        try {
            if ($fullreindex) {
                mtrace('Running full RAG reindex...');
                $result = $ragindexer->index(true, 0, $trace);
            } else {
                mtrace('Running incremental RAG index...');
                // Use a reasonable time limit for scheduled tasks (15 minutes)
                $defaulttimelimit = $timelimit ?: 900;
                $result = $ragindexer->index(false, $defaulttimelimit, $trace);
            }

            if ($result) {
                mtrace('RAG indexing completed successfully.');
                // Reset the full reindex flag if it was set
                if ($fullreindex) {
                    $this->reset_full_reindex_flag();
                }
            } else {
                mtrace('No documents were indexed.');
            }

        } catch (\Exception $e) {
            mtrace('Error during RAG indexing: ' . $e->getMessage());
            throw $e; // Re-throw to mark task as failed
        }

        mtrace('RAG indexer scheduled task completed.');
    }

    /**
     * Get the time limit for this task run.
     * 
     * @return int Time limit in seconds, or 0 for no limit
     */
    protected function get_time_limit() {
        // Check if there's a configured time limit for this task
        $timelimit = get_config('aipurpose_rag', 'task_time_limit');
        return $timelimit ? (int)$timelimit : 0;
    }

    /**
     * Check if we should run a full reindex instead of incremental.
     * 
     * @return bool True if full reindex should be run
     */
    protected function should_run_full_reindex() {
        // Check for a flag that requests full reindex
        $fullreindex = get_config('aipurpose_rag', 'task_full_reindex');
        return !empty($fullreindex);
    }

    /**
     * Reset the full reindex flag after successful completion.
     */
    protected function reset_full_reindex_flag() {
        set_config('task_full_reindex', 0, 'aipurpose_rag');
        mtrace('Full reindex flag reset.');
    }

    /**
     * Get the maximum time this task can run.
     * Override to provide longer time limits for indexing tasks.
     *
     * @return int Maximum runtime in seconds
     */
    public function get_timeout_after_task_claimed() {
        // Allow up to 2 hours for large reindexing operations
        $configuredtimeout = get_config('aipurpose_rag', 'task_max_timeout');
        return $configuredtimeout ? (int)$configuredtimeout : 7200; // Default: 2 hours
    }
}

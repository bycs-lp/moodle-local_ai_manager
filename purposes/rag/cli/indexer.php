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
 * RAG content indexer helper script
 *
 * This script provides instructions for running the RAG indexer
 * using Moodle's scheduled task system.
 *
 * @package    aipurpose_rag
 * @copyright  2025 University Of Strathclyde <learning-technologies@strath.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../../config.php');
require_once($CFG->libdir.'/clilib.php');      // cli only functions

list($options, $unrecognized) = cli_get_params(array('help' => false), array('h' => 'help'));

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    $help =
"RAG Content Indexer - Now Available as Scheduled Task

The RAG content indexer has been converted to a Moodle scheduled task for
better integration and automated execution.

SCHEDULED TASK (RECOMMENDED):
The RAG indexer runs automatically every 30 minutes as a scheduled task.
You can manage it through:
- Site administration → Server → Scheduled tasks
- Look for: 'RAG Content Indexer'

MANUAL EXECUTION:
To run the RAG indexer manually, use the scheduled task runner:

For incremental indexing (recommended):
\$ sudo -u www-data /usr/bin/php admin/cli/scheduled_task.php --execute=\\\\aipurpose_rag\\\\task\\\\indexer_task

For a full reindex, first set the reindex flag:
\$ sudo -u www-data /usr/bin/php admin/cli/cfg.php --name=task_full_reindex --set=1 --component=aipurpose_rag
\$ sudo -u www-data /usr/bin/php admin/cli/scheduled_task.php --execute=\\\\aipurpose_rag\\\\task\\\\indexer_task

CONFIGURATION:
You can configure the task behavior using these settings:
- task_time_limit: Maximum time per run (seconds)
- task_full_reindex: Set to 1 to trigger full reindex
- task_max_timeout: Maximum total timeout (default: 7200 seconds)

Example configuration commands:
\$ sudo -u www-data /usr/bin/php admin/cli/cfg.php --name=task_time_limit --set=900 --component=aipurpose_rag
\$ sudo -u www-data /usr/bin/php admin/cli/cfg.php --name=task_max_timeout --set=3600 --component=aipurpose_rag

LEGACY SUPPORT:
This script is maintained for compatibility but now provides instructions
for using the improved scheduled task system.
";

    echo $help;
    die;
}

// Display information about the new scheduled task system
echo "===========================================\n";
echo "RAG Content Indexer - Scheduled Task Info\n";
echo "===========================================\n";
echo "\n";
echo "The RAG content indexer is now available as a Moodle scheduled task.\n";
echo "This provides better integration, monitoring, and automated execution.\n";
echo "\n";
echo "CURRENT STATUS:\n";

// Check if the scheduled task exists
try {
    $task = \core\task\manager::get_scheduled_task('\\aipurpose_rag\\task\\indexer_task');
    if ($task) {
        echo "✓ Scheduled task is registered\n";
        echo "✓ Task name: " . $task->get_name() . "\n";
        echo "✓ Enabled: " . ($task->get_disabled() ? 'No' : 'Yes') . "\n";
        echo "✓ Schedule: " . $task->get_minute() . " " . $task->get_hour() . " " . 
             $task->get_day() . " " . $task->get_month() . " " . $task->get_day_of_week() . "\n";
        if ($task->get_last_run_time()) {
            echo "✓ Last run: " . userdate($task->get_last_run_time()) . "\n";
        } else {
            echo "• Last run: Never\n";
        }
        if ($task->get_next_run_time()) {
            echo "✓ Next run: " . userdate($task->get_next_run_time()) . "\n";
        }
    } else {
        echo "✗ Scheduled task not found\n";
        echo "  Please upgrade the plugin to register the scheduled task.\n";
    }
} catch (Exception $e) {
    echo "✗ Error checking scheduled task: " . $e->getMessage() . "\n";
}

echo "\n";
echo "RAG INDEXING STATUS:\n";

// Check if RAG indexing is enabled
if (\aipurpose_rag\indexer_manager::is_rag_indexing_enabled()) {
    echo "✓ RAG indexing is enabled\n";
} else {
    echo "✗ RAG indexing is disabled\n";
}

// Check global search status
if (\core_search\manager::is_global_search_enabled()) {
    echo "✓ Global search is enabled\n";
    
    if ($searchengine = \core_search\manager::search_engine_instance()) {
        if ($searchengine->is_installed()) {
            echo "✓ Search engine is installed (" . get_config('core', 'searchengine') . ")\n";
            
            $serverstatus = $searchengine->is_server_ready();
            if ($serverstatus === true) {
                echo "✓ Search engine server is ready\n";
            } else {
                echo "✗ Search engine server not ready: " . $serverstatus . "\n";
            }
        } else {
            echo "✗ Search engine not installed\n";
        }
    } else {
        echo "✗ Search engine not available\n";
    }
} else {
    echo "✗ Global search is disabled\n";
    echo "  RAG indexing requires global search to be enabled.\n";
}

echo "\n";
echo "MANUAL EXECUTION:\n";
echo "To run the RAG indexer manually:\n";
echo "\n";
echo "Incremental indexing:\n";
echo "  \$ sudo -u www-data /usr/bin/php admin/cli/scheduled_task.php --execute=\\\\aipurpose_rag\\\\task\\\\indexer_task\n";
echo "\n";
echo "Full reindex:\n";
echo "  \$ sudo -u www-data /usr/bin/php admin/cli/cfg.php --name=task_full_reindex --set=1 --component=aipurpose_rag\n";
echo "  \$ sudo -u www-data /usr/bin/php admin/cli/scheduled_task.php --execute=\\\\aipurpose_rag\\\\task\\\\indexer_task\n";
echo "\n";
echo "For more options, run:\n";
echo "  \$ sudo -u www-data /usr/bin/php " . basename(__FILE__) . " --help\n";
echo "\n";

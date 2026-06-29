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
 * RAG Indexer manager.
 *
 * @package    aipurpose_rag
 * @copyright  2025 University Of Strathclyde <learning-technologies@strath.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aipurpose_rag;

defined('MOODLE_INTERNAL') || die;

require_once($CFG->dirroot . '/lib/accesslib.php');
require_once($CFG->dirroot . '/lib/filelib.php');
require_once($CFG->dirroot . '/search/classes/manager.php');
require_once($CFG->dirroot . '/lib/classes/progress/base.php');

/**
 * RAG Indexer manager class based on core_search manager
 *
 * @package    aipurpose_rag
 * @copyright  2025 University Of Strathclyde <learning-technologies@strath.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class indexer_manager {

    /** @var float Time to wait before indexing new content, to ensure database transactions are complete */
    const INDEXING_DELAY = 5;

    /** @var int Maximum number of items to process in a single batch */
    const MAX_BATCH_SIZE = 100;

    /** @var \local_ai_manager\manager The AI manager instance */
    protected $aimanager;

    /** @var \core_search\manager The global search manager instance */
    protected $searchmanager;

    /** @var bool Whether the RAG indexing is enabled */
    protected static $enabled = null;

    /**
     * Constructor
     * 
     * @param \local_ai_manager\manager $aimanager The AI manager instance
     * @param \core_search\manager $searchmanager The global search manager instance
     */
    public function __construct($aimanager, $searchmanager) {
        $this->aimanager = $aimanager;
        $this->searchmanager = $searchmanager;
    }

    /**
     * Factory method to get a RAG indexer manager instance
     * 
     * @return \aipurpose_rag\indexer_manager|null The RAG indexer manager instance or null if disabled
     */
    public static function instance() {
        global $CFG;

        if (!self::is_rag_indexing_enabled()) {
            return null;
        }

        // Get the AI manager instance
        $aimanager = \local_ai_manager\manager::get_instance();
        if (!$aimanager) {
            return null;
        }

        // Get the global search manager instance
        if (!$searchmanager = \core_search\manager::instance()) {
            return null;
        }

        return new self($aimanager, $searchmanager);
    }

    /**
     * Checks if RAG indexing is enabled
     * 
     * @return bool True if enabled, false otherwise
     */
    public static function is_rag_indexing_enabled() {
        global $CFG;

        if (self::$enabled !== null) {
            return self::$enabled;
        }

        // First check if global search is enabled
//        if (!\core_search\manager::is_global_search_enabled()) {
//            self::$enabled = false;
//            return false;
//        }

        // Check if RAG purpose is enabled
        // These checks are hallucinations.
        // We need a deifferent way to ccheck the purpose is enabled.

//        if (!component_callback_exists('aipurpose_rag', 'is_available')) {
//            self::$enabled = false;
//            return false;
//        }
//
//        if (!component_callback('aipurpose_rag', 'is_available')) {
//            self::$enabled = false;
//            return false;
//        }

        // Check if RAG indexing is enabled in settings
        $enabled = get_config('aipurpose_rag', 'enableindexing');
        if (!isset($enabled) || $enabled === false) {
            self::$enabled = true; // Default to enabled if setting not found
        } else {
            self::$enabled = (bool)$enabled;
        }

        return self::$enabled;
    }

    /**
     * Reset cached static values
     */
    public static function clear_static() {
        self::$enabled = null;
    }

    /**
     * Index content from search areas for RAG
     *
     * @param bool $fullindex Whether to do a full reindex
     * @param int $timelimit Time limit in seconds (0 = no limit)
     * @param \core\progress\base|null $progress Optional progress tracker
     * @return bool True if any documents were indexed
     */
    public function index($fullindex = false, $timelimit = 0, ?\core\progress\base $progress = null) {
        global $DB;

        // Cannot combine time limit with reindexing
        if ($timelimit && $fullindex) {
            throw new \moodle_exception('Cannot apply time limit when reindexing');
        }

        // Use null progress object if none provided
        if (!$progress) {
            $progress = new \core\progress\none();
        }

        // Unlimited PHP time
        \core_php_time_limit::raise();

        // Get enabled search areas
        $searchareas = \core_search\manager::get_search_areas_list(true);
        if (empty($searchareas)) {
            debugging('No search areas enabled');
            return false;
        }
        debugging("Got ".count($searchareas) ." search areas", DEBUG_DEVELOPER);

        // Order search areas by previous indexing duration if time limited
        if ($timelimit) {
            // Sort by time taken in previous run, slowest areas first
            uasort($searchareas, function($a, $b) {
                $lasta = get_config('aipurpose_rag', 'lastindexrun' . $a->get_area_id());
                $lastb = get_config('aipurpose_rag', 'lastindexrun' . $b->get_area_id());
                
                if (empty($lasta) && empty($lastb)) {
                    return 0;
                }
                if (empty($lasta)) {
                    return -1;
                }
                if (empty($lastb)) {
                    return 1;
                }
                return (int)($lastb - $lasta);
            });
        }

        // Set time limit
        $stopat = 0;
        if ($timelimit) {
            $stopat = time() + $timelimit;
        }

        $anydocs = false;

        // Loop through each search area
        foreach ($searchareas as $areaid => $searcharea) {
            // Since we can't check if the area supports indexing, we'll just assume it does
            mtrace("\nIndexing area: {$areaid}");
            // Check time limit
            if ($stopat && time() >= $stopat) {
                break;
            }

            // Start time for this area
            $areastart = time();

            // Get the start time for indexing
            if ($fullindex) {
                // Full reindex - start from the beginning (zero)
                $startfrom = 0;
            } else {
                // Incremental index - start from the last indexed time
                $startfrom = get_config('aipurpose_rag', 'lastindexrun' . $areaid);
                if (!$startfrom) {
                    mtrace("No previous index time found, starting from the beginning");
                    $startfrom = 0;
                }
                mtrace("Indexing content modified after ". userdate($startfrom));
            }

            // Add a delay buffer to avoid race conditions
            $endtime = time() - self::INDEXING_DELAY;
            if ($endtime <= $startfrom) {
                continue;
            }

            // Get documents to index
            try {
                $records = $searcharea->get_recordset_by_timestamp($startfrom);
                if (!$records->valid()) {
                    mtrace("No records to index in this area");
                    continue;
                }
            } catch (\Exception $e) {
                continue;
            }

            // Process documents
            $numrecords = 0;
            $numdocs = 0;
            $docstoadd = [];
            $lastindexeddoc = 0;

            // Process each document
            foreach ($records as $record) {
                
                if (debugging("{$numrecords} Records to index", DEBUG_DEVELOPER)) {
                    print_r($record);
                }
                $numrecords++;
                // Check if we should skip this item based on timemodified
                // In a real implementation, we would use $searcharea->get_document_modified($record)
                // For now, use a default property if it exists, otherwise use current time
                
                $timemodified = time();
                if (isset($record->timemodified)) {
                    $timemodified = $record->timemodified;
                } else if (isset($record->modified)) {
                    $timemodified = $record->modified;
                }
                
                if ($timemodified > $endtime) {
                    mtrace('Continuing due to timemodified '.$timemodified.' > endtime '.$endtime);
                    continue;
                }

                // Update the last indexed document time
                $lastindexeddoc = $timemodified;
                mtrace("Last indexed document time updated to ". userdate($lastindexeddoc));

                // Skip deleted items - in a real implementation we'd use $searcharea->check_access($record->id)
                // For now, assume all records are accessible
                // $access = $searcharea->check_access($record->id);
                // if ($access == \core_search\manager::ACCESS_DELETED) {
                //     continue;
                // }

                try {
                    // Get the document
                    $doc = $searcharea->get_document($record);
                    if (!$doc) {
                        mtrace("couldn't get document for record id {$record->id}, skipping");
                        continue;
                    }

                    // Store document to be processed by AI manager
                    $docstoadd[] = [
                        'id' => $doc->get('id'),
                        'title' => $doc->get('title'),
                        'content' => $doc->get('content'),
                        'contextid' => $doc->get('contextid'),
                        'courseid' => $doc->get('courseid'),
                        'owneruserid' => $doc->get('owneruserid'),
                        'modified' => $doc->get('modified')
                    ];
                    $numdocs++;

                    // Process in batches
                    if (count($docstoadd) >= self::MAX_BATCH_SIZE) {
                        $this->store_documents($docstoadd, $progress);
                        $anydocs = true;
                        $docstoadd = [];
                    }
                } catch (\Exception $e) {
                    mtrace($e->getMessage());
                    continue;
                }

                // Check time limit again
                if ($stopat && time() >= $stopat) {
                    mtrace("Time limit reached, stopping indexing");
                    break;
                }
            }
            
            // Process any remaining documents
            if (!empty($docstoadd)) {
                $this->store_documents($docstoadd, $progress);
                $anydocs = true;
            }

            // Close the recordset
            $records->close();
            
            // Update the last indexed time for this area
            if ($lastindexeddoc) {
                mtrace("Updating last indexed time for area {$areaid} to ". userdate($lastindexeddoc));
                set_config('lastindexrun' . $areaid, $lastindexeddoc, 'aipurpose_rag');
            }

            // Record the time taken to index this area
            $timetaken = time() - $areastart;
            set_config('lastindexruntime' . $areaid, $timetaken, 'aipurpose_rag');
        }

        return $anydocs;
    }

    /**
     * Store documents in the RAG system using the AI manager's "store" action
     *
     * @param array $documents Array of document data
     * @param \core\progress\base|null $progress Optional progress tracker
     * @return bool True if successful
     */
    protected function store_documents($documents, ?\core\progress\base $progress = null) {

        $syscontext = \core\context\system::instance();
        if (empty($documents)) {
            return false;
        }

        $store = true;
        try {
            $ragmanager = new \local_ai_manager\manager('rag');
            
            // Process each document through the AI manager with "store" action
            foreach ($documents as $document) {
                if (empty($document['content'])) {
                    continue;
                }
                $options = [
                    'action' => 'store',
                    'content' => $document['content'],
                    'metadata' => [
                        'id' => $document['id'],
                        'title' => $document['title'],
                        'contextid' => $document['contextid'],
                        'courseid' => $document['courseid'],
                        'owneruserid' => $document['owneruserid'],
                        'modified' => $document['modified']
                    ]
                ];
                $response = $ragmanager->perform_request(
                    $document['content'],
                    'aipurpose_rag',
                    $syscontext->id,
                    $options
                );
                if ($response->get_code() !== 200) {
                    // Could log error if needed
                } else {
                    if ($progress) {
                        $progress->update_progress();
                    }
                }
            }
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}

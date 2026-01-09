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
 * Lang strings for aipurpose_rag - EN.
 *
 * @package    aipurpose_rag
 * @copyright  University of Strathclyde, 2025
 * @author     Michael Hughes
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'RAG';
$string['privacy:metadata'] = 'The local ai_manager purpose subplugin "RAG" does not store any personal data.';
$string['purposedescription'] = 'The purpose "RAG" is used for Retrieval Augmented Generation, providing content from Moodle to AI systems to improve their responses with contextual knowledge.';
$string['requestcount'] = 'RAG requests';
$string['requestcount_shortened'] = 'RAG';

// RAG Indexer strings
$string['indexer'] = 'RAG Indexer';
$string['indexer_desc'] = 'Index content from Moodle for use with Retrieval Augmented Generation (RAG)';
$string['enableindexing'] = 'Enable RAG indexing';
$string['enableindexing_desc'] = 'When enabled, content from Moodle will be indexed for use with RAG';
$string['indexingschedule'] = 'RAG indexing schedule';
$string['indexingschedule_desc'] = 'How often to run the RAG indexer task';
$string['indexer_status'] = 'RAG indexing status';
$string['indexer_status_desc'] = 'Current status of the RAG indexer';
$string['lastrun'] = 'Last indexing run';
$string['documentcount'] = 'Documents indexed';
$string['error_indexing'] = 'Error during RAG indexing: {$a}';
$string['indexing_success'] = 'RAG indexing completed successfully';
$string['indexing_partial'] = 'RAG indexing completed partially';
$string['indexing_empty'] = 'No documents were indexed';

// Scheduled task strings
$string['indexertask'] = 'RAG Content Indexer';
$string['indexertask_desc'] = 'Indexes content from across the site for use with Retrieval Augmented Generation (RAG)';

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
 * Lang strings for aipurpose_embedding - EN.
 *
 * @package    aipurpose_embedding
 * @copyright  2026 ISB Bayern
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['limitrequestswarning'] = 'We recommend not to limit this purpose. It is not used directly by users, but automatically in the background, for example when a source is being prepared so that the AI can use its content. Larger sources need many requests, so a limit can be reached quickly and the source can then not be used. A limit is not needed anyway, because the functions users work with (for example the chat) already have their own limit.';
$string['pluginname'] = 'Embedding';
$string['privacy:metadata'] = 'The local ai_manager purpose subplugin "Embedding" does not store any personal data.';
$string['purposedescription'] = 'The purpose "Embedding" is being used to turn text into numerical vector representations that capture semantic meaning. A text prompt is being sent to the external AI system and it will return an embedding vector, typically used for semantic search, clustering and retrieval-augmented generation.';
$string['requestcount'] = 'embedding requests';
$string['requestcount_shortened'] = 'embeddings';


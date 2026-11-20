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
 * Lang strings for aipurpose_searchquery - EN.
 *
 * @package    aipurpose_searchquery
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['limitrequestswarning'] = 'We recommend not to limit this purpose. It is not used directly by users, but automatically in the background whenever, for example, a chat message should take selected sources into account. If the limit is reached, the chat stops answering, even though the user has not yet reached the limit of the chat. A limit is not needed anyway, because the chat already has its own limit.';
$string['pluginname'] = 'Search query generation';
$string['privacy:metadata'] = 'The local ai_manager purpose subplugin "Search query generation" does not store any personal data.';
$string['purposedescription'] = 'The purpose "Search query generation" is being used internally to turn the latest message of a conversation and its history into a self-contained search query. The search query is then being used to search the selected sources for matching content.';
$string['requestcount'] = 'search query requests';
$string['requestcount_shortened'] = 'search queries';




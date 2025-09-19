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

namespace aipurpose_rag;

use local_ai_manager\base_purpose;

/**
 * Purpose class for Retrieval-Augmented Generation (RAG) search.
 */
class purpose extends base_purpose {
    // Optionally override methods for RAG search logic.
    // Implement logic to perform a RAG search and return the most appropriate results.

    #[\Override]
    public function format_output(string $output): string
    {
        $docs = json_decode($output);
        $docs = [
            [
            'title' => 'Yellow is blue',
            'url' => 'http://example.com/sample-document',
            'content' => 'Yellow is a shade of blue.'
            ]
        ];
        // Add the retrieved documents to the context for this chat by generating some system messages with the content
        // returned
        if (empty($docs)) {
            return "";
        } else {
            $contextdata = [];
            // Remember We've got a search_engine doc here!
            foreach ($docs as $doc) {
                $strdoc = "Title: {$doc['title']}\n";
                $strdoc .= "URL: {$doc['url']}\n";
                $strdoc .= $doc['content'] ;
                $contextdata[] = $strdoc;
            }
            return implode("\n", $contextdata);
        }
        return '';
    }
}

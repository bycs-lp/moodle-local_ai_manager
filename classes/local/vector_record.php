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

namespace local_ai_manager\local;

/**
 * Value object representing an embedding vector together with its content and payload.
 *
 * This object is used in both directions:
 * - When storing, callers create vector records and hand them to {@see \local_ai_manager\base_vecstore::insert_embeddings()}.
 * - When retrieving, each vecstore subplugin wraps its raw search response into vector records, so consumers can rely on a
 *   uniform data structure regardless of the underlying backend.
 *
 * The vector store does not interpret the document id and the metadata: the document id is a key chosen by the caller
 * (for example the id of the record the content has been taken from), which is used to replace and delete the vectors of a
 * document. The metadata is stored as is next to the content.
 *
 * @package    local_ai_manager
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class vector_record {
    /** @var string Payload key of the content. */
    const PAYLOAD_CONTENT = 'content';

    /** @var string Payload key of the document id. */
    const PAYLOAD_DOCUMENTID = 'documentid';

    /**
     * Constructor.
     *
     * @param string $vector The (JSON string representation of the) embedding vector.
     * @param string $content The textual content associated with the vector.
     * @param int $documentid The caller defined id of the document the vector belongs to, 0 if unknown.
     * @param array $metadata Additional scalar payload values indexed by payload key, stored next to the content.
     * @throws \coding_exception If the metadata uses a reserved payload key.
     */
    public function __construct(
        /** @var string The (JSON string representation of the) embedding vector. */
        private readonly string $vector,
        /** @var string The textual content associated with the vector. */
        private readonly string $content,
        /** @var int The caller defined id of the document the vector belongs to, 0 if unknown. */
        private readonly int $documentid,
        /** @var array Additional scalar payload values indexed by payload key. */
        private readonly array $metadata = [],
    ) {
        if (array_key_exists(self::PAYLOAD_CONTENT, $metadata) || array_key_exists(self::PAYLOAD_DOCUMENTID, $metadata)) {
            throw new \coding_exception('The metadata of a vector record must not use the reserved payload keys.');
        }
    }

    /**
     * Creates a vector record from a backend vector and its stored payload.
     *
     * @param string $vector The (JSON string representation of the) embedding vector.
     * @param array $payload The stored payload.
     * @return self The vector record.
     */
    public static function from_payload(string $vector, array $payload): self {
        // Vectors of collections managed outside of Moodle may lack any of these keys.
        $content = (string) ($payload[self::PAYLOAD_CONTENT] ?? '');
        $documentid = (int) ($payload[self::PAYLOAD_DOCUMENTID] ?? 0);
        unset($payload[self::PAYLOAD_CONTENT], $payload[self::PAYLOAD_DOCUMENTID]);
        return new self($vector, $content, $documentid, $payload);
    }

    /**
     * Returns the payload to store in the vector store backend.
     *
     * @return array The payload containing content, document id and metadata.
     */
    public function to_payload(): array {
        return [
            self::PAYLOAD_CONTENT => $this->content,
            self::PAYLOAD_DOCUMENTID => $this->documentid,
        ] + $this->metadata;
    }

    /**
     * Returns the embedding vector.
     *
     * @return string The (JSON string representation of the) embedding vector.
     */
    public function get_vector(): string {
        return $this->vector;
    }

    /**
     * Returns the content.
     *
     * @return string The textual content associated with the vector.
     */
    public function get_content(): string {
        return $this->content;
    }

    /**
     * Returns the document id.
     *
     * @return int The caller defined id of the document the vector belongs to, 0 if unknown.
     */
    public function get_documentid(): int {
        return $this->documentid;
    }

    /**
     * Returns the metadata.
     *
     * @return array Additional payload values indexed by payload key.
     */
    public function get_metadata(): array {
        return $this->metadata;
    }
}


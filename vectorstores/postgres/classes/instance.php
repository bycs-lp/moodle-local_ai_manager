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

namespace aivecstore_postgres;

use local_ai_manager\base_vecstore_instance;
use stdClass;

/**
 * Vector store instance class for the PostgreSQL/pgvector backend.
 *
 * PostgreSQL connects via a single libpq connection string (DSN) rather than the URL + API key
 * used by HTTP backends like Qdrant. This subclass therefore replaces those shared fields with one
 * masked "Connection string" field, which is persisted in the (masked) apikey column.
 *
 * @package    aivecstore_postgres
 * @copyright  2026 Exputo Inc.
 * @author     David Pesce <david.pesce@exputo.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class instance extends base_vecstore_instance {
    #[\Override]
    protected function extend_form_definition(\MoodleQuickForm $mform): void {
        // Postgres uses a single connection string instead of endpoint + API key, so remove those shared fields.
        foreach (['endpoint', 'endpointdescription', 'apikey', 'useglobalapikey'] as $element) {
            if ($mform->elementExists($element)) {
                $mform->removeElement($element);
            }
        }

        // Add a single masked connection-string field (it carries the password) just before the collection field.
        $connectionstringelement = $mform->createElement(
            'passwordunmask',
            'connectionstring',
            get_string('connectionstring', 'aivecstore_postgres'),
            ['style' => 'width: 100%', 'placeholder' => 'postgresql://user:password@host:5432/dbname?sslmode=require']
        );
        $mform->insertElementBefore($connectionstringelement, 'collection');
        $mform->setType('connectionstring', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('connectionstring', 'connectionstring', 'aivecstore_postgres');
    }

    #[\Override]
    protected function get_extended_formdata(): stdClass {
        // The connection string is stored in the apikey column; surface it under the form's field name.
        $data = new stdClass();
        $data->connectionstring = $this->get_apikey();
        return $data;
    }

    #[\Override]
    protected function extend_store_formdata(stdClass $data): void {
        // Persist the connection string into the (masked) apikey column.
        $this->set_apikey(!empty($data->connectionstring) ? trim($data->connectionstring) : '');
    }

    #[\Override]
    protected function extend_validation(array $data, array $files): array {
        $errors = [];
        if (empty($data['connectionstring'])) {
            $errors['connectionstring'] = get_string('formvalidation_connectionstring', 'aivecstore_postgres');
        }
        return $errors;
    }

    /**
     * Returns the configured PostgreSQL connection string (libpq DSN).
     *
     * @return ?string the connection string or null if not set
     */
    public function get_connectionstring(): ?string {
        return $this->get_apikey();
    }
}

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

namespace local_ai_manager\hook;

use local_ai_manager\local\tenant;

/**
 * Hook for providing information for the rights config table filter.
 *
 * This hook will be dispatched when it's rendering the rights config table.
 *
 * @package    local_ai_manager
 * @copyright  2024 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class usertable_filter implements \Psr\EventDispatcher\StoppableEventInterface {
    /** @var array associative array for providing filter options to the filter component of the rights config table */
    private array $filteroptions = [];

    /** @var string String for providing a label for the filter selection form element */
    private string $filterlabel = '';
    /** @var array array of currently selected filter ids */
    private array $selectedfilterids = [];
    /** @var string the SQL select statement that will be part of the WHERE clause of the table SQL */
    private string $filtersqlselect = '';
    /** @var array the SQL params array related to the SQL select statement that will be part of the WHERE clause of the table SQL */
    private array $filtersqlparams = [];
    /** @var bool if the further propagation of the hook should be stopped */
    private $propagationstopped = false;

    /**
     * Constructor for the hook.
     *
     * @param tenant $tenant the tenant for which the user table is being shown
     */
    public function __construct(
        /** @var tenant $tenant the tenant for which the user table is being shown */
        private tenant $tenant
    ) {
    }

    /**
     * Standard getter.
     *
     * @return tenant the tenant for which the table is being shown
     */
    public function get_tenant(): tenant {
        return $this->tenant;
    }

    /**
     * Standard getter.
     *
     * @return array filter options array
     */
    public function get_filter_options(): array {
        return $this->filteroptions;
    }

    /**
     * Standard setter to allow the hook callbacks to store the filter options.
     *
     * @param array $filteroptions associative array with the filter options of the form ['key' => 'displayname', ...] where 'key'
     *  is the key which is being submitted when submitting the filter form, 'displayname' is the (localized) name to show in the
     *  filter
     */
    public function set_filter_options(array $filteroptions): void {
        $this->filteroptions = $filteroptions;
    }

    /**
     * Standard getter for retrieving the label which should be shown above the filter form element.
     *
     * @return string the localized string to show above the filter form element
     */
    public function get_filter_label(): string {
        return $this->filterlabel;
    }

    /**
     * Standard setter for the label which should be shown above the filter form element.
     *
     * @param string $label The localized string to show above the filter form element.
     */
    public function set_filter_label(string $label): void {
        $this->filterlabel = $label;
    }

    /**
     * Standard getter for the filter ids that the user has currently selected.
     *
     * @return array filter ids (integers)
     */
    public function get_selected_filterids() {
        return $this->selectedfilterids;
    }

    /**
     * Standard setter for the filter ids that the user has currently selected.
     *
     * @param array $selectedfilterids array of integer filter ids that the user has selected in the filter
     */
    public function set_selected_filterids(array $selectedfilterids): void {
        $this->selectedfilterids = $selectedfilterids;
    }

    /**
     * Standard getter for the SQL snippet that will be appended to the WHERE part in the final table SQL statement.
     *
     * @return string the SQL snippet
     */
    public function get_filter_sql_select(): string {
        return $this->filtersqlselect;
    }

    /**
     * Standard setter for the SQL snippet that will be appended to the WHERE part in the final table SQL statement.
     *
     * @param string $filtersql the SQL snippet
     * @return void
     */
    public function set_filter_sql_select(string $filtersql): void {
        $this->filtersqlselect = $filtersql;
    }

    /**
     * Standard getter for the SQL params that go with the snippet in $this->filtersqlselect.
     *
     * @return array array of named params
     */
    public function get_filter_sql_params(): array {
        return $this->filtersqlparams;
    }

    /**
     * Standard setter for the SQL params that go with the snippet in $this->filtersqlselect.
     *
     * @param array $filtersqlparams array of named params
     */
    public function set_filter_sql_params(array $filtersqlparams): void {
        $this->filtersqlparams = $filtersqlparams;
    }

    /**
     * Stops the further propagation of the hook.
     *
     * Can be used by hook callbacks that want other callbacks not to be processed.
     */
    public function stop_further_propagation(): void {
        $this->propagationstopped = true;
    }

    #[\Override]
    public function isPropagationStopped(): bool {
        return $this->propagationstopped;
    }
}

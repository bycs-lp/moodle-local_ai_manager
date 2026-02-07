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

namespace local_ai_manager;

use context;
use core_plugin_manager;
use local_ai_manager\hook\additional_user_restriction;
use local_ai_manager\hook\purpose_usage;
use local_ai_manager\local\connector_factory;
use local_ai_manager\local\tenant;
use local_ai_manager\local\userinfo;
use local_ai_manager\local\userusage;
use moodle_url;
use stdClass;

/**
 * Base class for connector subplugins.
 *
 * @package    local_ai_manager
 * @copyright  ISB Bayern, 2024
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_manager_utils {
    /** @var string Constant declaring that a frontend plugin should be available in general. */
    const AVAILABILITY_AVAILABLE = 'available';

    /** @var string Constant declaring that a frontend plugin should be hidden in general. */
    const AVAILABILITY_HIDDEN = 'hidden';

    /** @var string Constant declaring that a frontend plugin should be disabled in general. */
    const AVAILABILITY_DISABLED = 'disabled';

    /**
     * API function to retrieve entries from the ai_manager logging table.
     *
     * @param string $component the component which has logged the records
     * @param int $contextid the contextid
     * @param int $userid the userid of the user, optional
     * @param int $itemid the itemid, optional
     * @param bool $includedeleted if log entries which are marked as deleted, should be included in the result
     * @param string $fields Comma separated list of SQL fields that should be contained in the result, defaults to all fields
     * @param array $purposes Array of purpose name strings that should be returned. If empty, all purposes will be returned.
     * @param int $limit the maximum number of records to return, sorted by timecreated descending.
     *  If 0, all records will be returned.
     * @return array array of records of the log table
     */
    public static function get_log_entries(
        string $component,
        int $contextid,
        int $userid = 0,
        int $itemid = 0,
        bool $includedeleted = true,
        string $fields = '*',
        array $purposes = [],
        int $limit = 0,
    ): array {
        global $DB;

        $conditions = [];
        $params = [];

        if (!empty($userid)) {
            $conditions[] = "userid = :userid";
            $params['userid'] = $userid;
        }

        $conditions[] = "contextid = :contextid";
        $conditions[] = "component = :component";
        $params['contextid'] = $contextid;
        $params['component'] = $component;

        if (!empty($itemid)) {
            $conditions[] = "itemid = :itemid";
            $params['itemid'] = $itemid;
        }
        if (empty($includedeleted)) {
            $conditions[] = "deleted = :deleted";
            // The column 'deleted' is defined to have value 0 by default, so we should be safe to use this as a query param.
            $params['deleted'] = 0;
        }
        if (!empty($purposes)) {
            [$insql, $inparams] = $DB->get_in_or_equal($purposes, SQL_PARAMS_NAMED);
            $conditions[] = "purpose " . $insql;
            $params = array_merge($params, $inparams);
        }
        $select = implode(' AND ', $conditions);
        $sort = $limit === 0 ? 'timecreated ASC' : 'timecreated DESC';
        if ($fields !== '*') {
            $fieldsarray = explode(',', $fields);
            // We always need id field to make results have a unique identifier.
            if (!in_array('id', $fieldsarray)) {
                $fieldsarray = ['id', ...$fieldsarray];
            }
            // We always need timecreated field to be able to sort properly.
            if (!in_array('timecreated', $fieldsarray)) {
                $fieldsarray[] = 'timecreated';
            }
            $fields = implode(',', $fieldsarray);
        }
        $records = $DB->get_records_select('local_ai_manager_request_log', $select, $params, $sort, $fields, 0, $limit);
        if ($limit !== 0) {
            uasort($records, function ($a, $b) {
                return $a->timecreated <=> $b->timecreated;
            });
        }
        return $records;
    }

    /**
     * Retrieves the entries from the request_log table and structures it for delivering them to the "view prompts" table.
     *
     * External function that delivers this data: int $contextid The main context id the prompts should be retrieved
     *
     * @param int $contextid the main context id the prompts should be retrieved for
     * @param int $userid the id of the user to retrieve the prompts
     * @param int $time the time since when the prompts should be retrieved
     * @return array complex structured array containing the prompts
     * @see \local_ai_manager\external\get_prompts
     *
     */
    public static function get_structured_entries_by_context(int $contextid, int $userid = 0, int $time = 0): array {
        global $DB;

        $maincontext = \context::instance_by_id($contextid);
        $tenant = \core\di::get(tenant::class);
        if ($tenant->get_context()->id === $maincontext->id) {
            $contextids = $DB->get_fieldset(
                'local_ai_manager_request_log',
                'DISTINCT contextid',
                ['tenant' => $tenant->get_sql_identifier(), 'userid' => $userid, 'coursecontextid' => SYSCONTEXTID]
            );
        } else if ($maincontext->contextlevel === CONTEXT_COURSE) {
            $contextids = $DB->get_fieldset(
                'local_ai_manager_request_log',
                'DISTINCT contextid',
                ['userid' => $userid, 'coursecontextid' => $maincontext->id]
            );
        }

        if (empty($contextids)) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED);

        $params = $inparams;
        if (!empty($userid)) {
            $params['userid'] = $userid;
        }

        $timesql = '';
        if (!empty($time)) {
            $timesql = " AND timecreated > :time";
            $params['time'] = $time;
        }
        $sql = "SELECT * FROM {local_ai_manager_request_log} WHERE userid = :userid AND contextid " . $insql . $timesql .
            " ORDER BY timecreated DESC";
        $records = $DB->get_records_sql($sql, $params);
        if (empty($records)) {
            return [];
        }

        $entries = [];
        $sequencenumber = 1;
        foreach ($records as $record) {
            $context = \context::instance_by_id($record->contextid, IGNORE_MISSING);
            $contextname = $context ? $context->get_context_name() : get_string('contextdeleted', 'local_ai_manager');
            $canviewpromptsdates = $context ? has_capability('local/ai_manager:viewpromptsdates', $context) : false;
            $promptobject = [
                'sequencenumber' => $sequencenumber,
                'prompt' => format_text($record->prompttext, FORMAT_MARKDOWN),
                'promptshortened' => self::shorten_prompt(format_text($record->prompttext, FORMAT_MARKDOWN)),
                'promptcompletion' => format_text($record->promptcompletion, FORMAT_MARKDOWN),
                'promptcompletionshortened' => self::shorten_prompt(format_text($record->promptcompletion, FORMAT_MARKDOWN)),
                'date' => $canviewpromptsdates ? $record->timecreated : 0,
            ];

            if (in_array($record->purpose, ['imggen', 'tts'])) {
                $promptobject['promptcompletion'] =
                    '| ' . get_string('promptcompletitionfilesnotavailable', 'local_ai_manager') . ' |';
                $promptobject['promptcompletionshortened'] = $promptobject['promptcompletion'];
            }

            if (array_key_exists($record->contextid, $entries)) {
                $promptobject['firstprompt'] = false;
                $entries[$record->contextid]['prompts'][] = $promptobject;
            } else {
                $promptobject['firstprompt'] = true;
                $entries[$record->contextid] = [
                    'contextid' => $record->contextid,
                    'contextdisplayname' => $contextname,
                    'prompts' => [$promptobject],
                    'viewpromptsdates' => $canviewpromptsdates,
                ];
            }
            $sequencenumber++;
        }
        foreach ($entries as $key => $value) {
            $entries[$key]['promptscount'] = count($value['prompts']);
        }
        return $entries;
    }

    /**
     * API function to mark log entries as deleted.
     *
     * @param string $component the component which has logged the records
     * @param int $contextid the contextid
     * @param int $userid the userid of the user, optional
     * @param int $itemid the itemid, optional
     * @return array of id numbers of record entries
     */
    public static function mark_log_entries_as_deleted(string $component, int $contextid, int $userid = 0, int $itemid = 0): array {
        global $DB;
        $params = [
            'component' => $component,
            'contextid' => $contextid,
        ];
        if (!empty($userid)) {
            $params['userid'] = $userid;
        }
        if (!empty($itemid)) {
            $params['itemid'] = $itemid;
        }
        // We intentionally do this one by one despite maybe not being very efficient to avoid running into transaction size limit
        // on DB layer.
        $rs = $DB->get_recordset('local_ai_manager_request_log', $params, '', 'id, deleted');
        $markedasdeletedids = [];
        foreach ($rs as $record) {
            $record->deleted = 1;
            $DB->update_record('local_ai_manager_request_log', $record);
            $markedasdeletedids[] = $record->id;
        }
        $rs->close();
        return $markedasdeletedids;
    }

    /**
     * API function to check if an itemid already exists.
     *
     * @param string $component the component to check
     * @param int $contextid the contextid to check
     * @param int $itemid the itemid that should be checked for existence
     * @return bool if the passed itemid in the context of the component and contextid already exists
     */
    public static function itemid_exists(string $component, int $contextid, int $itemid): bool {
        global $DB;
        return $DB->record_exists(
            'local_ai_manager_request_log',
            [
                'component' => $component,
                'contextid' => $contextid,
                'itemid' => $itemid,
            ]
        );
    }

    /**
     * API function to get the next unused itemid.
     *
     * @param string $component the component to retrieve the itemid for
     * @param int $contextid the contextid of the context to retrieve the itemid for
     * @return int the unused itemid
     */
    public static function get_next_free_itemid(string $component, int $contextid): int {
        global $DB;
        $sql = "SELECT MAX(itemid) as maxitemid FROM {local_ai_manager_request_log} "
            . "WHERE component = :component AND contextid = :contextid";
        $max =
            intval($DB->get_field_sql($sql, ['component' => $component, 'contextid' => $contextid]));
        return empty($max) ? 1 : $max + 1;
    }

    /**
     * API helper function to get the connector instance of a purpose
     *
     * @param string $purpose the purpose to get the connector instance for
     * @param ?int $userid the userid of the user to determine the correct tenant
     * @return base_instance the connector instance object
     */
    public static function get_connector_instance_by_purpose(string $purpose, ?int $userid = null): base_instance {
        global $USER;
        if (is_null($userid)) {
            $tenant = \core\di::get(tenant::class);
        } else {
            $user = \core_user::get_user($userid);
            $tenantfield = get_config('local_ai_manager', 'tenantcolumn');
            $tenant = new tenant($user->{$tenantfield});
            \core\di::set(tenant::class, $tenant);
        }
        $userinfo = new userinfo(empty($userid) ? $USER->id : $userid);
        $factory = \core\di::get(\local_ai_manager\local\connector_factory::class);
        return $factory->get_connector_instance_by_purpose($purpose, $userinfo->get_role());
    }

    /**
     * API function to get all needed information about the AI configuration for a user.
     *
     * @param stdClass $user the user to retrieve the information for
     * @param int $contextid the contextid on which the availability should be determined
     * @param ?string $tenant the tenant to retrieve the information for. If null, the current tenant will be used
     * @param ?array $selectedpurposes array of purpose strings to check. If empty all purposes will be checked
     * @return array complex associative array containing all the needed configurations
     */
    public static function get_ai_config(
        stdClass $user,
        int $contextid,
        ?string $tenant = null,
        ?array $selectedpurposes = []
    ): array {
        if (!is_null($tenant)) {
            $tenant = new tenant($tenant);
            \core\di::set(tenant::class, $tenant);
        }
        $tenant = \core\di::get(tenant::class);

        $installedpurposes = array_keys(core_plugin_manager::instance()->get_installed_plugins('aipurpose'));
        if (empty($selectedpurposes)) {
            // If no purpose is specified, we return the config for all purposes.
            $selectedpurposes = $installedpurposes;
        }

        $availability = self::determine_availability($user, $tenant, $contextid);
        $purposes = self::determine_purposes_availability($user, $contextid, $selectedpurposes);

        return [
            'availability' => $availability,
            'purposes' => $purposes,
        ];
    }

    /**
     * API function to get general information about the AI manager.
     *
     * @param ?string $tenant the tenant to retrieve the information for. If null, the current tenant will be used
     * @return array associative array containing the general info object
     */
    public static function get_ai_info(?string $tenant = null): array {
        if (!is_null($tenant)) {
            $tenant = new tenant($tenant);
            \core\di::set(tenant::class, $tenant);
        }
        $tenant = \core\di::get(tenant::class);

        $tools = [];
        foreach (\local_ai_manager\plugininfo\aitool::get_enabled_plugins() as $toolname) {
            $tool['name'] = $toolname;
            $addurl = new moodle_url(
                '/local/ai_manager/edit_instance.php',
                [
                    'tenant' => $tenant->get_identifier(),
                    'returnurl' => (new moodle_url(
                        '/local/ai_manager/tenant_config.php',
                        ['tenant' => $tenant->get_identifier()]
                    ))->out(),
                    'connectorname' => $toolname,
                ]
            );
            $tool['addurl'] = $addurl->out(false);
            $tools[] = $tool;
        }
        // If the warning url is empty, we will not show a link.
        $aiwarningurl = get_config('local_ai_manager', 'aiwarningurl') ?: '';

        return [
            'aiwarningurl' => $aiwarningurl,
            'tools' => $tools,
        ];
    }

    /**
     * Determines the closest course parent context based on the past context.
     *
     * Will return null if the context has no parent course context.
     *
     * @param context $context The context to find the closest parent course context for
     * @return context|null The closest parent course context or null if there is not course parent context
     */
    public static function find_closest_parent_course_context(context $context): ?context {
        if ($context->contextlevel < CONTEXT_COURSE) {
            // There can't be a course context in a context with contextlevel below course context,
            // because these are CONTEXT_SYSTEM, CONTEXT_USER, CONTEXT_COURSECAT.
            return null;
        }
        if ($context->contextlevel === CONTEXT_COURSE) {
            return $context;
        }
        return self::find_closest_parent_course_context($context->get_parent_context());
    }

    /**
     * Helper function to add a category to a (course edit) form.
     *
     * This can be called by other AI plugins using the {@see \core_course\hook\after_form_definition} hook to extend
     * the course edit form. Calling this function will add an AI tools category below which the plugins can add their
     * mform elements. This function will only add a category if there does not exist one yet.
     *
     * @param \MoodleQuickForm $mform the mform object to add the heading to
     */
    public static function add_ai_tools_category_to_mform(\MoodleQuickForm $mform): void {
        if (!$mform->elementExists('aitoolsheader')) {
            $mform->addElement('header', 'aitoolsheader', get_string('aicourseeditheader', 'local_ai_manager'));
        }
    }

    /**
     * Small helper function to generate a shortened (preview) version of a prompt or prompt completion.
     *
     * @param string $prompt the prompt to shorten
     * @return string the shortened prompt with HTML tags being stripped
     */
    private static function shorten_prompt(string $prompt): string {
        $prompt = strip_tags($prompt);
        $length = mb_strlen($prompt);
        $shortened = mb_substr($prompt, 0, 50);
        return mb_strlen($shortened) === $length ? $prompt : $shortened . '...';
    }

    /**
     * Utility function to retrieve a good display name for a context in the local_ai_manager.
     *
     * Will usually return the context name. If the context is the tenant context, the tenant name will be returned.
     *
     * @param context $context the context to retrieve the name for
     * @param ?tenant $tenant the tenant to retrieve the name from if the context is the tenant context
     * @return string the context display name
     */
    public static function get_context_displayname(\context $context, ?tenant $tenant = null): string {
        if (!is_null($tenant) && $tenant->get_context()->id === $context->id) {
            return get_string('tenant', 'local_ai_manager') . ': ' . $tenant->get_fullname();
        } else {
            return $context->get_context_name();
        }
    }

    /**
     * Determine the general availability of a frontend plugin.
     *
     * @param stdClass $user the user to check
     * @param tenant $tenant the tenant to check
     * @param int $contextid the context on which the availability should be determined
     * @return array array with keys 'available' and 'errormessage' declaring the general
     *  availability for a frontend plugin
     */
    public static function determine_availability(stdClass $user, tenant $tenant, int $contextid) {
        $availability = [];
        $availability['available'] = self::AVAILABILITY_AVAILABLE;
        $availability['errormessage'] = '';

        if (!has_capability('local/ai_manager:use', \context_system::instance(), $user->id)) {
            $availability['available'] = self::AVAILABILITY_HIDDEN;
            return $availability;
        }

        if (!$tenant->is_tenant_allowed()) {
            $availability['available'] = self::AVAILABILITY_HIDDEN;
            return $availability;
        }

        $configmanager = \core\di::get(\local_ai_manager\local\config_manager::class);
        if (!$configmanager->is_tenant_enabled()) {
            $availability['available'] = self::AVAILABILITY_HIDDEN;
            return $availability;
        }

        $userinfo = new userinfo($user->id);
        $context = context::instance_by_id($contextid);
        if ($userinfo->get_scope() === userinfo::SCOPE_COURSES_ONLY) {
            $parentcoursecontext = self::find_closest_parent_course_context($context);
            if (is_null($parentcoursecontext)) {
                $availability['available'] = self::AVAILABILITY_HIDDEN;
                return $availability;
            }
        }

        // From here on the checks that cause the state "disabled" are being performed.
        if ($userinfo->is_locked()) {
            $availability['available'] = self::AVAILABILITY_DISABLED;
            $availability['errormessage'] = get_string('error_http403blocked', 'local_ai_manager');
            return $availability;
        }

        if (!$userinfo->is_confirmed()) {
            $availability['available'] = self::AVAILABILITY_DISABLED;
            $url = new moodle_url('/local/ai_manager/confirm_ai_usage.php');
            $confirmlink = \html_writer::link($url, $url->out(), ['target' => '_blank']);
            $availability['errormessage'] = get_string('error_http403notconfirmed', 'local_ai_manager')
                . '. ' . get_string('useconfirmlink', 'local_ai_manager', $confirmlink);
            return $availability;
        }

        return $availability;
    }

    /**
     * Determine the availability of a certain purpose in a frontend plugin.
     *
     * @param stdClass $user the user to check
     * @param int $contextid the context on which the availability should be determined
     * @param array $selectedpurposes array of purpose strings to check. If empty all purposes will be checked
     * @return array array containing arrays of the form ['purpose' => ..., 'available' => ..., 'errormessage' => ...]
     *  that provides the information if the purpose should be available in a frontend plugin
     */
    public static function determine_purposes_availability(stdClass $user, int $contextid, array $selectedpurposes): array {
        if (empty($selectedpurposes)) {
            return [];
        }

        $configmanager = \core\di::get(\local_ai_manager\local\config_manager::class);
        $userinfo = new userinfo($user->id);
        $context = \context::instance_by_id($contextid);

        $purposes = [];
        $purposeconfig = $configmanager->get_purpose_config($userinfo->get_role());
        $factory = \core\di::get(\local_ai_manager\local\connector_factory::class);
        $enabledpurposes = \local_ai_manager\plugininfo\aipurpose::get_enabled_plugins();
        foreach (array_keys(core_plugin_manager::instance()->get_installed_plugins('aipurpose')) as $purpose) {
            if (!in_array($purpose, $selectedpurposes)) {
                continue;
            }

            if (!in_array($purpose, $enabledpurposes)) {
                $purposes[] = [
                    'purpose' => $purpose,
                    'available' => self::AVAILABILITY_HIDDEN,
                    'errormessage' => get_string(
                        'error_purposedisabled',
                        'local_ai_manager',
                        get_string('pluginname', 'aipurpose_' . $purpose)
                    ),
                ];
                continue;
            }

            $purposeinstance = $factory->get_purpose_by_purpose_string($purpose);
            $userusage = new userusage($purposeinstance, $user->id);
            if (empty($purposeconfig[$purpose])) {
                $purposes[] = [
                    'purpose' => $purpose,
                    'available' => self::AVAILABILITY_DISABLED,
                    'errormessage' => get_string(
                        'error_purposenotconfigured',
                        'local_ai_manager',
                        get_string('pluginname', 'aipurpose_' . $purpose)
                    ),
                ];
                continue;
            }

            // If the connector plugin to which the instance configured for this purpose belongs, is disabled or not available,
            // we disable the frontend plugin for this purpose.
            $instance = $factory->get_connector_instance_by_purpose($purpose, $userinfo->get_role());
            if (!$instance->is_enabled()) {
                $purposes[] = [
                    'purpose' => $purpose,
                    'available' => self::AVAILABILITY_DISABLED,
                    'errormessage' => get_string('exception_instanceunavailable', 'local_ai_manager'),
                ];
                continue;
            }

            if (
                $userusage->get_currentusage() >=
                $configmanager->get_max_requests($purposeinstance, $userinfo->get_role())
            ) {
                $purposes[] = [
                    'purpose' => $purpose,
                    'available' => self::AVAILABILITY_DISABLED,
                    'errormessage' => get_string('error_limitreached', 'local_ai_manager'),
                ];
                continue;
            }

            if ($configmanager->get_max_requests($purposeinstance, $userinfo->get_role()) === 0) {
                $purposes[] = [
                    'purpose' => $purpose,
                    'available' => self::AVAILABILITY_DISABLED,
                    'errormessage' => get_string(
                        'error_purposenotconfigured',
                        'local_ai_manager',
                        get_string('pluginname', 'aipurpose_' . $purpose)
                    ),
                ];
                continue;
            }

            // Provide an additional hook for further limiting access.
            $restrictionhook = new additional_user_restriction($userinfo, $context, $purposeinstance);
            \core\di::get(\core\hook\manager::class)->dispatch($restrictionhook);
            if (!$restrictionhook->is_allowed()) {
                $purposes[] = [
                    'purpose' => $purpose,
                    'available' => self::AVAILABILITY_HIDDEN,
                    'errormessage' => $restrictionhook->get_message(),
                ];
                continue;
            }

            $purposes[] = [
                'purpose' => $purpose,
                'available' => self::AVAILABILITY_AVAILABLE,
                'errormessage' => '',
            ];
        }
        return $purposes;
    }

    /**
     * Helper function for external plugins to retrieve the available options for a given purpose.
     *
     * @param string $purpose the purpose name
     * @return array array of available purpose options including type
     */
    public static function get_available_purpose_options(string $purpose): array {
        $factory = \core\di::get(connector_factory::class);
        $purposeobject = $factory->get_purpose_by_purpose_string($purpose);
        return $purposeobject->get_available_purpose_options();
    }

    /**
     * Returns the description of a purpose.
     *
     * @param string $purpose the purpose name
     * @return string the description provided by the purpose plugin
     */
    public static function get_purpose_description(string $purpose): string {
        $connectorfactory = \core\di::get(connector_factory::class);
        $purposeinstance = $connectorfactory->get_purpose_by_purpose_string($purpose);
        return $purposeinstance->get_description();
    }

    /**
     * Returns the purpose usage info collected by the purpose_info hook.
     *
     * @return array the purpose usage info array
     */
    public static function get_purposes_usage_info(): array {
        $purposeusagehook = new purpose_usage();
        \core\di::get(\core\hook\manager::class)->dispatch($purposeusagehook);
        $purposeusageinfo = $purposeusagehook->get_purposes_usage_info();

        $returnarray = [];
        $connectorfactory = \core\di::get(connector_factory::class);
        foreach (\local_ai_manager\plugininfo\aipurpose::get_enabled_plugins() as $purpose) {
            $purposeinstance = $connectorfactory->get_purpose_by_purpose_string($purpose);
            $purposearray = [
                'purposename' => $purpose,
                'purposedisplayname' => get_string('pluginname', 'aipurpose_' . $purpose),
                'purposedescription' => $purposeinstance->get_description(),
            ];
            if (isset($purposeusageinfo[$purpose]) && !empty($purposeusageinfo[$purpose])) {
                $data = $purposeusageinfo[$purpose];

                foreach ($data as $component => $placedescriptions) {
                    $componentarray = [];
                    $componentarray['component'] = $component;
                    $componentarray['componentdisplayname'] = $purposeusagehook->get_component_displayname($component);
                    $componentarray['placedescriptions'] = [];
                    foreach ($placedescriptions as $placedescription) {
                        $componentarray['placedescriptions'][] = ['description' => $placedescription];
                    }
                    $purposearray['components'][] = $componentarray;
                }
            }
            $returnarray['purposes'][] = $purposearray;
        }
        return $returnarray;
    }
}

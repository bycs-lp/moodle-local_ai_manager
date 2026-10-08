# Devdocs for plugins that want to use local_ai_manager

Using the local_ai_manager as AI backend plugin in your "frontend plugin" is as easy as that:

```PHP
$manager = new \local_ai_manager\manager('singleprompt');
$result = $manager->perform_request('Tell a joke!', 'local_myplugin', $contextid);
echo 'Here\'s an AI generated joke: ' . $result->get_content();
```

The parameters used are:
- `'singleprompt'`: When creating the manager object, you need to pass the identifier of the purpose plugin you want to use when interacting with the external AI system. For more information about purposes check [../dev/purposes.md](../dev/purposes.md).
- For each request you will have to pass the component name of the plugin you are using the AI manager from. This is used for logging purposes as well as providing logged data later on.
- For each request you will also have to pass the id of the context the call is made from. The context is being used to determine the availability of the AI functionality as well as for logging.

# 1. Public API: what external plugins may use

**You do not have to care about tenants.** Everything in the `local_ai_manager` depends on a tenant, but by default the tenant of the current user is being used, determined anew on every access. As a frontend plugin you always want exactly this, so just use the API below, do not pass any tenant and you are done. This also works in scheduled/adhoc tasks: switch to the user with `\core\cron::setup_user($user)` before calling the API and the tenant follows the user.

Only if a *specific* tenant is needed (which is the case for the administration pages of the `local_ai_manager` itself, not for frontend plugins), the tenant is set **once at the entry point** (page script or external function) after checking the access, and all code below automatically works with it. Such a set tenant is global state: it stays active for the rest of the PHP process and is not being reset automatically in cron or CLI scripts. See [../dev/index_dev.md](../dev/index_dev.md#5-tenants-and-dependency-injection) for the details of this concept.

Plugins using the `local_ai_manager` ("frontend plugins") **must only use the following public API**:

| Kind | Public API |
|---|---|
| PHP | `\local_ai_manager\manager` (constructor and `perform_request()`) |
| PHP | public static methods and `AVAILABILITY_*` constants of `\local_ai_manager\ai_manager_utils`, see [section 2](#2-php-api-functions) (with the restrictions noted there) |
| Hooks (listen to) | `\local_ai_manager\hook\purpose_usage` (declare which purposes your plugin uses), `\local_ai_manager\hook\additional_user_restriction` (further restrict the access to purposes, see `block_ai_control`) |
| JS (AMD) | `local_ai_manager/config` (`getAiConfig`, `getPurposeOptions`), `local_ai_manager/make_request`, `local_ai_manager/infobox`, `local_ai_manager/warningbox`, `local_ai_manager/userquota`, `local_ai_manager/events`, see [section 3](#3-js-api-functions) and [section 4](#4-obligatoryrecommended-widgets) |

Additionally, there are hooks for **integration plugins** that map the Moodle user base to tenants: `custom_tenant`, `userinfo_extend`, `usertable_extend` and `usertable_filter`. Frontend plugins must not listen to these hooks. Callbacks of `custom_tenant` should throw a `moodle_exception` (for example `invalid_parameter_exception`) for unknown tenants, which `access_manager::require_tenant_access()` converts into an "access denied" exception (real database errors are passed through).

**Everything else is internal**, especially all classes in the namespace `\local_ai_manager\local` (`tenant`, `tenant_factory`, `access_manager`, `config_manager`, `connector_factory`, `userinfo`, `userusage`, `data_wiper`, ...), `base_purpose`, `base_connector`, `base_instance` and the subplugin classes. Internal classes may change without notice. In particular, frontend plugins must not

- create or set tenants (`new tenant(...)`, `tenant_factory::set()`, `\core\di::set()`),
- retrieve tenant dependent objects (`config_manager`, `access_manager`, `connector_factory`) from the DI container to make their own access decisions. Use `ai_manager_utils::get_ai_config()` instead, it bundles all these checks.

**Why this is important:** Frontend plugins are no entry points for a specific tenant. Functions setting a tenant do not check the access of the user to this tenant, and the set tenant stays active for the rest of the PHP process - in cron this means: for all following tasks of the same cron run.

# 2. PHP API functions

All PHP API functions that are intended to be used by "frontend plugins" are public methods of the class `\local_ai_manager\ai_manager_utils`. Of course, there's one exception that is the manager class (see introduction example at the top). Here we pick a few which are considered worth knowing, in each case see PHP Docs for details:

- `ai_manager_utils::get_log_entries`

  Function for retrieving entries from the ai_manager internal request log store.

- `ai_manager_utils::mark_log_entries_as_deleted`

  Log entries can be marked as "deleted". They will stay in the request log, just get flagged as deleted. For example, `block_ai_chat` makes use of this for providing the users the option to delete conversations while at the same time make sure the request log entries are not being removed, so statistics are still correct.

- `ai_manager_utils::itemid_exists`

  Helper function to check, if an itemid already exists in the request log for a given component and context. An itemid is an identifier that plugins can use for their own purposes. For example, `block_ai_chat` uses the itemid option to identify a conversation.

- `ai_manager_utils::get_next_free_itemid`

  Helper function to get the next free itemid for a given component and context. For example, `block_ai_chat` needs such a function to retrieve a free itemid when creating a new conversation.

- `ai_manager_utils::get_connector_instance_by_purpose`

  Helper function to get the connector instance for a given purpose and - if specified - for a given user. This basically extracts which tenant belongs to the user, looks up what connector is currently configured for the given purpose and the user's AI manager internal role and returns the correct connector instance.

  **CARE:** If a userid is passed, the tenant of this user is set as current tenant for the rest of the PHP process (side effect, no access check). Frontend plugins should not pass a userid; in tasks switch the user via `\core\cron::setup_user()` instead.

- `ai_manager_utils::get_ai_config`

  First of all, see [section 5 "Availability of AI functionalities"](#5-availability-of-ai-functionalities) before continuing to read.

  **CARE:** Always pass `null` as `$tenant` parameter. Passing a tenant identifier sets this tenant as current tenant for the rest of the PHP process (side effect, no access check). The same applies to `ai_manager_utils::get_ai_info`.

  This function returns a big array containing all the information about the availability of the AI functionalities. It returns the array
  ```php
  [
      'availability' => $availability,
      'purposes' => $purposes,
  ]
  ```
  where the values are
  ```php
  $availability = self::determine_availability($user, $tenant, $contextid);
  $purposes = self::determine_purposes_availability($user, $contextid, $selectedpurposes);
  ```
  The availability information is being encoded like this:
  ```php
  [
      'availability' => [
          'available' => 'available',
          'errormessage' => '', // errormessage is not empty, if 'available' has the value 'disabled', so frontend plugins can show this error message to the user
      ],
      'purposes' => [
          [
              'purpose' => 'chat',
              'available' => 'available',
              'errormessage' => '',
          ],
          [
              'purpose' => 'singleprompt',
              'available' => 'disabled',
              'errormessage' => 'You have reached the maximum amount of requests allowed',
          ],
          [
              'purpose' => 'translate',
              'available' => 'hidden', // For example, a plugin has used the additional_user_restriction hook to block this specific purpose for a user
              'errormessage' => '',
          ],
      ],
  ]
  ```
  There is also the JS module `local_ai_manager/config` that includes the function `getAiConfig` that returns the same information on JS side. See the plugins `tiny_ai` and `block_ai_chat` as an example how to deal with this kind of information.

  Of course, it's up to the plugin developer if he/she wants to make use of this information. But to keep a consistent user experience, it's recommended to keep the user feedback unified between the frontend plugins and to share proper feedback to the user if and eventually why not the AI functionalities are currently available or not.

- `ai_manager_utils::determine_availability`

  Helper function to determine the *general* availability of the AI functionalities for the current user. See `ai_manager_utils::get_ai_config` for details. As it requires an (internal) `tenant` object, frontend plugins should use `ai_manager_utils::get_ai_config` instead.

- `ai_manager_utils::determine_purposes_availability`

  Helper function to determine the availability of the AI functionalities for the current user for a given purpose. See `ai_manager_utils::get_ai_config` for details.

- `ai_manager_utils::add_ai_tools_category_to_mform`

  Some plugins (like `block_ai_chat` and `block_ai_control`) add settings to the course edit form. To group all these settings together this function should be called in such frontend plugins when extending the course edit form. See `block_ai_chat` and `block_ai_control` for a usage example.

- `ai_manager_utils::get_available_purpose_options`

  Each purpose exposes options that can be sent along the prompt when calling the `perform_request` method of the `manager` class. This helper function retrieves the currently available purpose options as well as valid values. See `\qbank_questiongen\local\question_generator::is_mimetype_supported_by_ai_system` for an example.


# 3. JS API functions

- `local_ai_manager/config` - `getAiConfig`

  The JS version of `ai_manager_utils::get_ai_config`, retrieves the availability information for frontend plugins that use JS (see `tiny_ai` and `block_ai_chat` which heavily make use of this). Do not pass the `tenant` parameter, so the tenant of the current user is being used.

- `local_ai_manager/config` - `getPurposeOptions`
  
  The JS version of `ai_manager_utils::get_available_purpose_options`, retrieves the currently available purpose options for frontend plugins that use JS (see `tiny_ai` and `block_ai_chat` which heavily make use of this).

- `local_ai_manager/make_request` - `makeRequest`

  Basic JS function for communicating with the local_ai_manager backend for retrieving an AI result. It basically is a JS wrapper for the `manager` class and the `perform_request` method. See `tiny_ai` and `block_ai_chat` for an example.


# 4. Obligatory/recommended widgets

## 4.1 infobox

The infobox is a small widget that informs the user about the fact that he/she is about to share information with an external AI system. It also contains a link to the page /local/ai_manager/ai_info.php that contains additional information (terms of use, which models/services are being used for which purpose etc.).

To include the infobox into your plugin, create a HTML element (for example an element `<div data-myplugin="aiinfo"></div>`) and call the JS module like this:

```php
$PAGE->requires->js_call_amd('local_ai_manager/infobox', 'renderInfoBox',
    ['local_myplugin', $USER->id, '[data-myplugin="aiinfo"]', ['singleprompt', 'translate']]);
```
Note that the last entry in the array is an array of purposes that are being used by your plugin `local_myplugin`. This is being used for the ai_info.php page to highlight which purposes are currently being used by the plugin.

Of course, this can also be done directly inside JS:
```js
import {renderInfoBox} from 'local_ai_manager/infobox';

...
    await renderInfoBox('local_myplugin', userId, '[data-myplugin="aiinfo"]', ['singleprompt', 'translate']);
...
```

CARE: This is a leftover from early development, you do not need to pass a valid user id, because it's not used anymore. The userid option will soon be removed.


## 4.2 warningbox

The warningbox is a small widget that informs the user about the fact that AI results have to be used carefully. It should be shown inside the frontend plugin whenever an AI result is being displayed.

Rendering it is even easier than rendering the infobox. Just create an HTML element (for example an element `<div data-myplugin="aiwarning"></div>`) and call the JS module like this:

```php
$PAGE->requires->js_call_amd('local_ai_manager/warningbox', 'renderWarningBox', ['[data-myplugin="aiwarning"]']);
```

Of course, this again can also be done directly inside JS:
```js
import {renderWarningBox} from 'local_ai_manager/warningbox';

...
    await renderWarningBox('[data-myplugin="aiwarning"]');
...
```


## 4.3 quota

Requests are limited per user and purpose in a configurable time frame. Therefore, it should be made transparent to the user how many requests there are left for a given purpose as well as the timeframe. The quota widget is a small widget that does exactly that.

Rendering is pretty similar to rendering the infobox.

```php
$PAGE->requires->js_call_amd('local_ai_manager/userquota', 'renderUserQuota',
    ['[data-myplugin="aiuserquota"]', ['singleprompt', 'translate']]);
```
or again in JS:
```js
import {renderUserQuota} from 'local_ai_manager/userquota';

...
    await renderUserQuota('local_myplugin', '[data-myplugin="aiuserquota"]', ['singleprompt', 'translate']);
...
```


# 5. Availability of AI functionalities

The `local_ai_manager` has a lot of different configuration options that determine if a specific AI functionality is available for the current user. Examples:
- Is the tenant of the user locked by the site admin?
- Has the tenant of the user been enabled?
- Is a tool configured for the given purpose and the user's role?
- Has the user been locked by the tenant manager?
- Has the purpose been locked by additional plugins (like the control center - `block_ai_control`) inside a course?
- Has the user access to AI functionalities outside courses?
- Has the user reached the quota?
- ...

On the one hand, all these conditions are, of course, being checked when a user tries to interact with an external AI system through `local_ai_manager` and - if one requirement is not met - the user will be shown an error message. However, this is not a very good user experience, because the user invests time in creating a big prompt just to be informed that his quota has been reached when submitting.

On the other hand, making frontend plugins implement all these checks before submitting the AI request is not bearable for plugin developers. Therefore, the `local_ai_manager` provides a function that returns the availability information for the current user. It basically distinguishes between:
- "available": The functionality is available and a request can be sent to the external AI system
- "disabled": The functionality is disabled and should be shown to the user, but in a disabled state. An "errormessage" is being provided to inform the user why the functionality is not available to him
- "hidden": This means that the functionality is not available in a way that we do not even want the user to know that this functionality exists. For example, if a tenant is locked completely from the site admin, it makes no sense that we show and load the `tiny_ai` plugin in the tiny editor or show a chatbot button on the dashboard when the user won't ever be able to use them.

You can use the `ai_manager_utils::get_ai_config` function to retrieve the availability information for the current user, see [section 2 "PHP API functions"](#2-php-api-functions) -> `ai_manager_utils::get_ai_config` for details.

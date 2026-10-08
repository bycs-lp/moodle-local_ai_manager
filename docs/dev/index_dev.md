# 1 Basic usage/example 

If a plugin wants to use an external AI system through the *local_ai_manager*, this can be as easy as that:
```PHP
$manager = new \local_ai_manager\manager('singleprompt');
$promptresponse = $manager->perform_request('tell a joke', 'mod_myplugin', $contextid);
echo $promptresponse->get_content();
```
After instantiating the manager by passing a string identifying the purpose one wants to use, the `perform_request` method is being called with the prompt, the component name of the plugin from which the manager is being called and the id of the context from which the request is being made (required for the manager to be able to check if the user is allowed to use AI in this context for example).

Everything else is just being handled by the manager object: Sanitizing, identifying which tenant should be used, checking if the user has sufficient permissions, does not extend the quota, getting the configured external AI service, send the prompt to the external AI system, handle the response and wrapping everything into the *prompt_response* object.

Of course, there also is a JS module for calling the external AI system, see function *make_request* from the module *local_ai_manager/make_request*.

# 2 Purposes (aipurpose subplugins in /local/ai_manager/purposes)

See [purposes.md](purposes.md) for more information.


# 3 Tools (aitool subplugins in /local/ai_manager/tools)

See [tools.md](tools.md) for more information.


# 4 Model management

Model definitions are managed centrally in the model management UI and stored in DB tables used by connectors and the configuration UI.

Navigation path: *Site administration* -> *Plugins* -> *Local plugins* -> *AI manager* -> *Manage models*.

The JSON file `local/ai_manager/db/models.json` is primarily used for bootstrapping/import convenience.

Import happens automatically on install/upgrade and can also be triggered manually:
```bash
php local/ai_manager/cli/import_models.php
```

Repeated imports are idempotent regarding duplicates: existing models and existing model/connector assignments are not inserted again.

Important: re-import does not update existing model fields; it only adds missing models and missing connector assignments.

# 5 Tenants and dependency injection

## 5.1 The idea: the tenant of the current user by default, a specific tenant only via the entry point

Almost everything in the `local_ai_manager` depends on a tenant (configuration, tools, quota, rights, ...). Nevertheless, **in
the vast majority of cases you do not have to care about the tenant at all**, because what you want is simply the tenant of the
current user - and that is exactly what you get by default.

The concept behind this:

1. **There is exactly one "current tenant" per PHP process.** It is provided by `\local_ai_manager\local\tenant_factory`, a
   singleton in the DI container:
   ```PHP
   $tenant = \core\di::get(\local_ai_manager\local\tenant_factory::class)->get();
   ```
2. **Default: tenant of the current user.** As long as no tenant has been set explicitly, `tenant_factory::get()` determines the
   tenant from the current `$USER` on **every** call. So nobody has to set, pass or reset anything, and switching the user (for
   example with `\core\cron::setup_user($user)` in a task) automatically switches the tenant as well.
3. **A specific tenant is set once at the entry point.** Only if a different tenant than the one of the current user is needed
   (for example a tenant manager or site admin configuring a tenant via `tenant_config.php?tenant=...`), the entry point (the
   page script or the external function) checks the access and sets the tenant **once** at the very beginning.
4. **All code below the entry point automatically works with this tenant.** The tenant is **not** passed through the call chain.
   `config_manager`, `access_manager`, `connector_factory`, `ai_manager_utils`, `manager` etc. get the `tenant_factory` injected
   respectively ask it whenever they need the tenant, so after setting the tenant at the entry point everything is consistent
   without rebuilding or rebinding any object.

Retrieving the tenant directly from the DI container (`\core\di::get(\local_ai_manager\local\tenant::class)`) is prohibited and
throws a `coding_exception` (see `hook_callbacks::configure_di()`).

**The price of this concept:** Setting a tenant changes global state. The set tenant stays active until the end of the PHP process
and is only reset automatically before each external function (see 5.4). So whoever sets a tenant is responsible for checking the
access to it and - outside of page requests and web services (cron, CLI) - for resetting it afterwards.

## 5.2 Case A: the tenant of the current user (default, nothing to do)

This is the case for all frontend plugins and for most code of the `local_ai_manager` itself: just use the API, do not set any
tenant and pass `null` for optional tenant parameters (respectively no userid, see 5.5).

This also applies to scheduled/adhoc tasks and CLI scripts: switch to the user the work should be done for with
`\core\cron::setup_user($user)`, the tenant follows the user automatically. Do **not** set a tenant there.

## 5.3 Case B: a specific tenant is needed (set it at the entry point)

Entry points (pages, external functions) which want to work with a tenant other than the one of the current user check the access
first and then set the tenant, before anything else is done:
```PHP
\core\di::get(\local_ai_manager\local\access_manager::class)->require_tenant_access($tenantid);
\core\di::get(\local_ai_manager\local\tenant_factory::class)->set(new \local_ai_manager\local\tenant($tenantid));
```
Examples in the `local_ai_manager`: the tenant configuration pages (via `tenant_config_output_utils`), `edit_instance.php`,
`view_prompts.php`, `ai_info.php` and the external functions `local_ai_manager_get_ai_config` / `local_ai_manager_get_ai_info`.

`require_tenant_access()` allows members and managers of the tenant. It throws the same exception (`exception_tenantaccessdenied`)
for missing permissions and for invalid or non-existing tenants (invalid identifier, tenant unknown to the `custom_tenant` hook
implementation, missing tenant context record), so it cannot be used for finding out which tenants exist. Real database errors
(`dml_exception` except `dml_missing_record_exception`) are passed through and are not disguised as a missing permission.

If you are **not** at an entry point (for example in a task or CLI script) and really need to work with a specific tenant, reset it
afterwards, because there is nobody who does this for you (see 5.4):
```PHP
$tenantfactory = \core\di::get(\local_ai_manager\local\tenant_factory::class);
$tenantfactory->set(new \local_ai_manager\local\tenant($tenantid));
try {
    // Do the work for this tenant.
} finally {
    $tenantfactory->reset();
}
```
Alternatively, if only some objects are needed for a specific tenant, do not touch the current tenant at all and pass a separate
`tenant_factory` instance (see 5.6).

## 5.4 Lifetime of a set tenant

`tenant_factory` is a singleton in the DI container, so **a set tenant stays active for the rest of the PHP process**:

| Environment | Is the set tenant reset automatically? |
|---|---|
| Page request | No, but the process ends with the request. |
| Web service / AJAX (`lib/ajax/service.php`, `webservice/*`) | Yes, before **each** external function (see `local_ai_manager_override_webservice_execution()`), so a tenant set by one external function of a batch request cannot leak into the following ones. |
| Cron (scheduled and adhoc tasks) | **No.** Several tasks run one after another in the same PHP process and the DI container is not reset between them. |
| CLI scripts | **No.** |
| PHPUnit | Yes, the DI container is reset after each test. |

This is why cron and CLI code should stick to case A (5.2) and - if a specific tenant is unavoidable - reset it (5.3). Otherwise
a tenant set by one task would silently be used by all following tasks of the same cron run.

## 5.5 Functions which set the current tenant as side effect

The following functions call `tenant_factory::set()` internally, so calling them with the respective parameter is like being an
entry point of case B. They do **not** check if the current user is allowed to access the tenant, this is the responsibility of the
caller. The tenant stays active as described in 5.4.

| Function | Sets the tenant if ... |
|---|---|
| `ai_manager_utils::get_connector_instance_by_purpose($purpose, $userid)` | `$userid` is not null (tenant of the passed user) |
| `ai_manager_utils::get_ai_config($user, $contextid, $tenant, $purposes)` | `$tenant` is not null |
| `ai_manager_utils::get_ai_info($tenant)` | `$tenant` is not null |

Frontend plugins should pass `null` for the tenant (respectively no userid), so the tenant of the current user is being used
(case A). The external functions `local_ai_manager_get_ai_config` and `local_ai_manager_get_ai_info` (and so the JS functions
`getAiConfig` / `getAiInfo` of `local_ai_manager/config`) call `require_tenant_access()` before passing a tenant.

## 5.6 Rules

- If you need the tenant of the current user: do nothing (case A).
- Only entry points of `local_ai_manager` itself (pages, external functions) should set the tenant of the DI container's
  `tenant_factory`, once at the beginning and always after calling `require_tenant_access()` (or an equivalent check like
  `require_tenant_manager()`).
- Never set a tenant in scheduled/adhoc tasks or CLI scripts without resetting it afterwards (see 5.3 and 5.4).
- Do not pass tenants through the call chain; code below the entry point gets the tenant from the `tenant_factory`.
- Do not use `\core\di::set()` for `tenant`, `tenant_factory`, `config_manager`, `access_manager` or `connector_factory` outside
  of unit tests.
- If you need objects for a specific tenant without changing the current tenant, pass a separate `tenant_factory` instance:
  ```PHP
  $tenantfactory = new \local_ai_manager\local\tenant_factory();
  $tenantfactory->set($tenant);
  $configmanager = new \local_ai_manager\local\config_manager($tenantfactory);
  ```
- Plugins using the `local_ai_manager` must only use the public API described in
  [../plugindev/index_plugindev.md](../plugindev/index_plugindev.md#1-public-api-what-external-plugins-may-use). All classes in
  the namespace `\local_ai_manager\local` are internal.

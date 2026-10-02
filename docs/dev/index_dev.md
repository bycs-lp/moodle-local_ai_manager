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

The tenant the code is currently working with is provided by `\local_ai_manager\local\tenant_factory`, a singleton in the DI
container:
```PHP
$tenant = \core\di::get(\local_ai_manager\local\tenant_factory::class)->get();
```
If no tenant has been set explicitly, this is the tenant of the current user. Retrieving the tenant directly from the DI container
(`\core\di::get(\local_ai_manager\local\tenant::class)`) is prohibited and throws a `coding_exception`
(see `hook_callbacks::configure_di()`).

The tenant dependent objects retrieved from the DI container (`config_manager`, `access_manager`, `connector_factory`) always work
with the current tenant: `config_manager` and `access_manager` get the `tenant_factory` object injected instead of a tenant and ask it
whenever they need the tenant, so they never have to be rebuilt or rebound.

Entry points (pages, external functions) which want to work with another tenant set it and check the access:
```PHP
\core\di::get(\local_ai_manager\local\tenant_factory::class)->set(new \local_ai_manager\local\tenant($tenantid));
$accessmanager = \core\di::get(\local_ai_manager\local\access_manager::class);
if (!$accessmanager->is_tenant_member() && !$accessmanager->is_tenant_manager()) {
    \core\di::get(\local_ai_manager\local\tenant_factory::class)->reset();
    throw new \moodle_exception('exception_tenantaccessdenied', 'local_ai_manager', '', $tenantid);
}
```
The set tenant stays active for the rest of the page request respectively the rest of the external function call. Before each
external function, the tenant factory is reset (see `local_ai_manager_override_webservice_execution()`), so a tenant set by one
external function of a batch request (for example via `lib/ajax/service.php`) cannot leak into the following ones.

Rules:
- Do not use `\core\di::set()` for `tenant`, `tenant_factory`, `config_manager`, `access_manager` or `connector_factory` outside
  of unit tests.
- If you need objects for a specific tenant without changing the current tenant, pass a separate `tenant_factory` instance:
  ```PHP
  $tenantfactory = new \local_ai_manager\local\tenant_factory();
  $tenantfactory->set($tenant);
  $configmanager = new \local_ai_manager\local\config_manager($tenantfactory);
  ```

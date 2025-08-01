# Developer documentation for local_ai_manager

## 1. Introduction

The plugin *local_ai_manager* is designed to be the heart of plugins wanting to use AI features anywhere inside a moodle platform. It basically is an alternative to the moodle core_ai subsystem, but targets different needs. Main differences are:
- Tenant mode
- At the moment features (image analysis, text to speech, ...)
- More control of the users, designed to be used in schools, especially also with younger children

This documentation should allow plugin developers to understand how *local_ai_manager* is built and how to contribute to the development.

## 2. Architecture

### 2.1 Introduction

If a plugin wants to use an external AI system through the *local_ai_manager*, this can be as easy as that:
```PHP
$manager = new \local_ai_manager\manager('singleprompt');
$promptresponse = $manager->perform_request('tell a joke', 'mod_myplugin', $contextid);
echo $promptresponse->get_content();
```
After instantiating the manager by passing a string identifying the purpose one wants to use, the `perform_request` method is being called with the prompt, the component name of the plugin from which the manager is being called and the id of the context from which the request is being made (required for the manager to be able to check if the user is allowed to use AI in this context for example).

Everything else is just being handled by the manager object: Sanitizing, identifying which tenant should be used, checking if the user has sufficient permissions, does not extend the quota, getting the configured external AI service, send the prompt to the external AI system, handle the response and wrapping everything into the *prompt_response* object.

Of course, there also is a JS module for calling the external AI system, see function *make_request* from the module *local_ai_manager/make_request*.


### 2.2 Tenant mode

The most important difference to the moodle core_ai subsystem probably is the tenant mode. The whole system is designed to be tenant-aware, meaning nearly each single configuration is different in each tenant. To which tenant a user belongs is being determined by a database field in the user table. There is an admin setting *local_ai_manager/tenantcolumn* that currently allows the site admin to define if the field "institution" (default) or "department" should be used to determine to which tenant a user belongs.

**CAVEAT: If a user should not be allowed to switch tenants by himself/herself the site admin has to take care that a user cannot edit the institution/department field.**

Each tenant can have a tenant manager. Which user is a tenant manager can be controlled by the capability `local/ai_manager:manage`. Users with this capability will have access to the tenant configuration sites including user restriction management, quota config, purpose configuration as well as configuration of the connectors for the external AI systems to use. A user with the capability `local/ai_manager:managetenants` will be able to control **all** tenants by accessing https://yourmoodle.com/local/ai_manager/tenant_config.php?tenant=tenantidentifier directly.

If the institution (or department) field is empty, this means the "default tenant" is being used for this user.


### 2.3 Capabilities

Each user that wants to use the *local_ai_manager* has to have the capability `local/ai_manager:use' on system context.

For capabilities for tenant managers, see section about "Tenant mode".

Tenant managers can have additional capabilities:
- `local/ai_manager:viewstatistics`: Allows the tenant manager to view aggregated statistics of his tenant.
- `local/ai_manager:viewuserstatistics`: Allows the tenant manager to view user-specific statistics of users in his tenant.
- `local/ai_manager:viewusernames`: Allows the tenant manager to view the users' names in the user-specific statistics in his tenant.
- `local/ai_manager:viewusage`: Allows the tenant manager to view the users' usage statistics in the user-specific statistics in his tenant.

Other capabilities are:
- `local/ai_manager:viewprompts`: Allows a user to view the prompts that have been sent from other users in the contexts where this capability has been set as well as the AI responses.
- `local/ai_manager:viewtenantprompts`: Allows a user to view the prompts that have been sent from other users **in his tenant** as well as the AI responses.
- `local/ai_manager:viewpromptsdates`: Users with one of the *viewprompts* capabilities that also have `local/ai_manager:viewpromptsdates` can view the date and time the prompts have been sent to the external AI system.


### 2.4 Purposes (aipurpose subplugins in /local/ai_manager/purposes)

See [purposes.md](purposes.md) for more information.


### 2.5 Tools (aitool subplugins in /local/ai_manager/tools)

See [tools.md](tools.md) for more information.


## 3. Admin settings
# Docs for admins

# 1 Architecture

[comment]: # (TODO: write!)

## 2.1 Tenancy

The most important difference to the moodle core_ai subsystem probably is the tenant mode. The whole system is designed to be tenant-aware, meaning nearly each single configuration is different in each tenant. To which tenant a user belongs is being determined by a database field in the user table. There is an admin setting *local_ai_manager/tenantcolumn* that currently allows the site admin to define if the field "institution" (default) or "department" should be used to determine to which tenant a user belongs.

**CAVEAT: If a user should not be allowed to switch tenants by himself/herself the site admin has to take care that a user cannot edit the institution/department field.**

Each tenant can have a tenant manager. Which user is a tenant manager can be controlled by the capability `local/ai_manager:manage`. Users with this capability will have access to the tenant configuration sites including user restriction management, quota config, purpose configuration as well as configuration of the connectors for the external AI systems to use. A user with the capability `local/ai_manager:managetenants` will be able to control **all** tenants by accessing https://yourmoodle.com/local/ai_manager/tenant_config.php?tenant=tenantidentifier directly.

If the institution (or department) field is empty, this means the "default tenant" is being used for this user.


## 2.2 Tools


## 2.3 Purposes


## 2.4 User management (including roles)


## 2.5 Limits


## 2.6 Statistics

## 3 Capabilities

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

### 4 Admin settings
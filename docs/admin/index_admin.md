# Docs for site admins

# 1 Architecture

The plugin suite around the plugin `local_ai_manager` provides integration of AI functionalities into a moodle platform.

Core: The heart of the plugin suite is `local_ai_manager`. It provides...
- ... API functions to allow frontend plugins to use AI functionalities.
- ... a configuration tool for allowing a tenant manager/admin to configure the AI functionalities including rate limiting, (un)locking of users, limiting access to users based on scope, assigning internal roles, view statistics and more.
- ... widgets that can be included in the frontend plugins.
- ... a modular architecture that allows configuring different AI services.

The flow for every AI system interaction is:
```
-> Frontend plugin
  -> call the AI manager with a purpose (can be done via JS or PHP)
    -> selected purpose subplugin defines, checks, and sanitizes options passed alone
      -> purpose passes information to the connector (AI tool)
        -> connector implements the specific API call to the external AI system
          -> connector calls external AI system and retrieves information
            -> purpose sanitizes/manipulates output
              -> output is being passed back to the frontend plugin
```

## 2.1 Tenant support

The most important difference to the moodle core_ai subsystem probably is the tenant mode. The whole system is designed to be tenant-aware, meaning nearly each single configuration is different in each tenant. To which tenant a user belongs is being determined by a database field in the user table. There is an admin setting *local_ai_manager/tenantcolumn* that currently allows the site admin to define if the field "institution" (default) or "department" should be used to determine to which tenant a user belongs.

**CAVEAT: If a user should not be allowed to switch tenants by himself/herself the site admin has to take care that a user cannot edit the institution/department field.**

Each tenant can have one or more tenant managers. Which user is a tenant manager can be controlled by the capability `local/ai_manager:manage`. Users with this capability will have access to the tenant configuration sites including user restriction management, quota config, purpose configuration as well as configuration of the connectors for the external AI systems to use, but only **for their tenant**. A user with the capability `local/ai_manager:managetenants` will be able to control **all** tenants by accessing [https://yourmoodle.com/local/ai_manager/tenant_config.php?tenant=tenantidentifier](https://yourmoodle.com/local/ai_manager/tenant_config.php?tenant=tenantidentifier) directly.

If the institution (or department) field is empty, this means the "default tenant" is being used for this user. The tenant config sites are available under [https://yourmoodle.com/local/ai_manager/tenant_config.php?tenant=default](https://yourmoodle.com/local/ai_manager/tenant_config.php?tenant=default).


## 2.2 Tools

The AI manager plugin is designed to be very versatile and flexible. It serves as proxy for all kinds of AI interactions and allows other plugins (in this documentation mostly referred as "frontend plugins") to use AI functionalities without having to know which AI service is being used. Frontend plugins will not know if the request they are sending will end up at an Azure endpoint with a GPT model, at a Google endpoint with Gemini 2.5 Flash or if it's being sent to a local ollama server running models like Llama, Qwen, phi or anything else.

The AI manager provides different connectors for different API endpoints. The tenant manager at first creates an "AI tool" which is basically a configuration instance (set of configuration parameters) for a connector. After that this AI tool can be assigned to a purpose. Frontend plugins that use this purpose will automatically use this specific AI tool.


## 2.3 Purposes

There are a lot of different types of AI interactions, starting from generating text output over analyzing or extracting information/text from images to generating audio or images. For each kind of this different types of interactions the AI manager has a separate purpose that the frontend plugins can make us of. For example, there is a purpose "Single prompt" for generating text based on a single prompt. Further purposes for example are "Image generation", "Text to speech" or "Question generation". Each purpose can be configured differently to use a different AI tool.


## 2.4 User management (including roles)

Each tenant manager has control over the users of his tenant. On the AI tools administration navigation under "User configuration" -> "Rights configuration" the tenant manager can see all the users belonging to his tenant as well as certain information of the users:
- *Role*: The AI manager internal role. Possible values: basic, extended, unlimited.
- *Locked*: If the user is locked for using the AI manager. If set to locked, the user will not be able to use **any** AI tools with the AI manager.
- *Accepted*: If the user has accepted the terms of use and thus is able to AI functionalities of plugins that use the AI manager.
- *usage scope*: The usage scope of the user. Possible values: "Everywhere", "Only in courses". Users with scope "Everywhere" can use the AI manager inside and outside of courses (for example a chat bot on the dashboard). Users with scope "Only in courses" are only allowed to use AI tools on contexts inside of courses. Example use case: You do not want younger students to have access to AI tools outside of courses, because noone would be responsible to take care what students do. In a course a teacher has full control of the students' AI usage by using [block_ai_control](https://github.com/bycs-lp/moodle-block_ai_control).

Each of the given information about the users can be changed by the tenant manager. Just select the checkboxes besides the users, scroll at the end of the table and choose the desired action. The filter at the top of the table can help select the desired users. Especially roles can be assigned and unassigned. For example if a "trusted" user needs to use a lot of AI tools in a given timeframe, he can be assigned the role "unlimited" and will not have any quota at all.


## 2.5 Limits

In the AI tools administration navigation under "User configuration" -> "Limits configuration" the tenant manager can see and configure the limits of the roles for each purpose. The numbers are always "request counts". At the top of the page the tenant manager can configure for which duration the limits should be applied.

*Example*:
- Time window for maximum number of requests: 2 hour
- Maximum number of requests for purpose "chat" for base role : 50
- Maximum number of requests for purpose "chat" for extended role : 100

This means that a user with the base role can send 50 chat requests in 2 hours. After 2 hours the counter will be reset to 0 and he can again send 50 chat requests.

Setting a limit to 0 means that the purpose is completely locked for users with this role.

## 2.6 Statistics

In the AI tools administration navigation under "Statistics" you find some usage statistics allowing you to keep control of AI costs and general usage. All statistics are only for the current tenant. Have a look at the section about *Capabilities*, because what is being shown to a tenant manager is being heavily controlled by capabilities to find the perfect balance between allowing the tenant manager to have access to neccessary information while at the same time keeping the highest possible level of data protection.

- *Global overview*: Shows the total number of requests and token usage grouped by model.
- *User overview*: Shows the request count for each user.
- *View prompts*: Here the tenant manager can view the prompts that have been sent from the users in his tenant on contexts outside of courses. Of course, he is also able to view prompts that have been sent from other users inside courses he has the correct capability for. See capabilities `local/ai_manager:viewprompts` and `local/ai_manager:viewtenantprompts`.
- Below you find user statistics for each purpose.
- Depending on the capabilities user names might be anonymized or parts of the mentioned statistics pages might be unavailable.


## 3 Capabilities

Each user that wants to use the *local_ai_manager* has to have the capability `local/ai_manager:use' on system context.

For capabilities for tenant managers, see section "Tenant support".

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

See the description strings in the admin settings page. Please raise an issue if you consider a setting to be not well enough described.


### 5 Configuration of a tenant

Steps to make the AI functionalities available for users of a tenant:

- Some frontend plugins that use the AI manager need to be installed. For example:
  - [block_ai_chat](https://github.com/bycs-lp/moodle-block_ai_chat)
  - [tiny_ai](https://github.com/bycs-lp/moodle-tiny_ai)
  - [qtype_aitext](https://github.com/marcusgreen/moodle-qtype_aitext)
  - [qbank_questiongen](https://github.com/bycs-lp/moodle-qbank_questiongen)
  - ([block_ai_control](https://github.com/bycs-lp/moodle-block_ai_control) -> just for further controlling AI access inside a course)
- The tenant manager has to configure the tenant. This can be done by clicking on "AI tools administration" in the primary navigation (or use the deeplink [https://yourmoodle.com/local/ai_manager/tenant_config.php](https://yourmoodle.com/local/ai_manager/tenant_config.php)).
- First of all, the AI tools need to be enabled for this tenant by switching the toggle on.
- After that at least one "AI tool" needs to be added. Select the connector you want to use to configure your AI tool in the modal (for example "Gemini"). Insert the information for accessing your external AI service.
- After that go to the "Purposes" page and assign the created AI tool to one or more purposes.
- You may want to also configure some specific limits in "User configuration" -> "Limits configuration".
- From that on users of the tenant should be able to use the AI tools.

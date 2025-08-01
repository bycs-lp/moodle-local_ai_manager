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

Most important difference to the moodle core_ai subsystem probably is the tenant mode. The whole system is designed to be tenant aware, meaning nearly each single configuration is different in each tenant. To which tenant a user belongs is being determined by a database field in the user table. There is an admin setting *local_ai_manager/tenantcolumn* that currently allows the site admin to define if the field "institution" (default) or "department" should be used to determine to which tenant a user belongs.

**CAVEAT: If a user should not be allowed to switch tenants by himself/herself the site admin has to take care that a user can not edit the institution/department field.**

Each tenant can have a tenant manager. Which user is a tenant manager can be controlled by the capability `local/ai_manager:manage`. Users with this capability will have access to the tenant configuration sites including user restriction restriction, quota config, purpose configuration as well as configuration of the connectors for the external AI systems to use. A user with the capability `local/ai_manager:managetenants` will be able to control **all** tenants by accessing https://yourmoodle.com/local/ai_manager/tenant_config.php?tenant=tenantidentifier directly.

If the institution (or department) field is empty, this means the "default tenant" is being used for this user.

### 2.3 Capabilities



### 2.3 Purposes (aipurpose subplugins in /local/ai_manager/purposes)

Whenever a call to an external AI system is being made, you need to specify which purpose you want to use. 

Currently implemented purposes are *chat*, *feedback*, *imggen* (image generation), *itt* (image to text), *questiongeneration*, *singleprompt*, $translate*, *tts* (text to speech). Every interaction with an external AI system needs to define which purpose it wants to use.

- *Option definitions*: The purpose is responsible for defining, sanitizing and providing additional options that are allowed to be sent along the prompt.
For example, when using the purpose *itt* the purpose plugin defines that an option 'image' can be passed to the *perform_request* method that contains the base64 encoded image that should be passed to the external AI system. It also provides the option *allowed_mimetypes* to the "frontend" plugin so that the plugin sees what mimetypes are supported by the currently used external AI system.
- *Manipulating output*: The formatting of the output is also dependent from the used purpose. For example, the purpose *questiongeneration* takes care of formatting the output in a way that only the bare XML of a generated moodle question is being returned in the correct formatting (stripping additional blah blah of the LLM as well as for example markdown formatting, fixing encoding etc.).
- *Quota*: The user quota is bound to a certain purpose. That means for the basic role a quota of 50 *chat* requests per hour can be defined, for purpose *itt* it's just 10 requests per hour and purpose *imggen* is set to 0 requests per hour which means usage of this purpose is completely disabled for the role.
- *Access control*: By using an additional plugin *block_ai_control* (https://moodle.org/plugins/block_ai_control | https://github.com/bycs-lp/moodle-block_ai_control) you can allow teachers in a course to enable and disable the different purposes in their courses. 
- *Statistics*: Statistics are being provided grouped by purposes, so you can tell for which the external AI systems are being used for.



### 2.4 Tools (aitool subplugins in /local/ai_manager/tools)

The aitool subplugins are also referred to *connector* plugins - they are similar to the provider plugins of the core_ai subsystem. The aitool subplugins are the connectors to the external AI systems, handling the configuration and the actual communication with the external AI system.

Currently available aitool subplugins (connectors) are:
- the OpenAI connectors: *chatgpt*, *dalle*, *openaitts*, all of them also support Azure OpenAI
- the Google connectors: *gemini*, *googlesynthesize*, *imagen*
- Other connectors:
  - *ollama*: connector for ollama instances that are accessible with Bearer token authentication and via HTTPS
  - *telli*: connector providing different models (text, image generation etc.) for German schools

You can define different connector *instances* (referred to "AI tool" in the frontend) which basically are configurations of a connector. For example, you can define a "chatgpt 4o precise" instance which uses the chatgpt connector, sets the model to use "gpt-4o" and is configured to use a very low value for the temperature parameter. Besides that you can just define another instance "chatgpt 4o creative" that also uses "gpt-4o" as model, but with a higher temperature parameter. You then can define which instance should be used for which purpose, for example purpose *feedback* should use "chatgpt 4o precise", purpose *chat* should use "chatgpt 4o creative".

The connector plugins basically define which models can be used, which parameters are being passed to the external AI systems, take care of the API responses and return the output back to the purpose which then hands it back to the manager. Switching the AI system is as easy as changing which instance should be used by a purpose.




## 3. Admin settings
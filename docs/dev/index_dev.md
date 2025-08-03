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


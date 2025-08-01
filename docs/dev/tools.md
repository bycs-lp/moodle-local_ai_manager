# aitool subplugins ("connector subplugins")

## 1. General information (also for non-developers)

The aitool subplugins are also referred to *connector* plugins - they are similar to the provider plugins of the core_ai subsystem. The aitool subplugins are the connectors to the external AI systems, handling the configuration and the actual communication with the external AI system.

Currently available aitool subplugins (connectors) are:
- the OpenAI connectors: *chatgpt*, *dalle*, *openaitts*, all of them also support Azure OpenAI
- the Google connectors: *gemini*, *googlesynthesize*, *imagen*
- Other connectors:
    - *ollama*: connector for ollama instances that are accessible with Bearer token authentication and via HTTPS
    - *telli*: connector providing different models (text, image generation etc.) for German schools

You can define different connector *instances* (referred to "AI tool" in the frontend) which basically are configurations of a connector. For example, you can define a "chatgpt 4o precise" instance which uses the chatgpt connector, sets the model to use "gpt-4o" and is configured to use a very low value for the temperature parameter. Besides that you can just define another instance "chatgpt 4o creative" that also uses "gpt-4o" as model, but with a higher temperature parameter. You then can define which instance should be used for which purpose, for example purpose *feedback* should use "chatgpt 4o precise", purpose *chat* should use "chatgpt 4o creative".

The connector plugins basically define which models can be used, which parameters are being passed to the external AI systems, take care of the API responses and return the output back to the purpose which then hands it back to the manager. Switching the AI system is as easy as changing which instance should be used by a purpose.

## 2. Write your own aitool subplugin ("connector subplugin")

The subplugins of type *aitool* are located in the *local/ai_manager/tools/* directory. You can copy one of the existing subplugins and adapt it to your needs.

The following files should be self-explanatory:
- classes/privacy/provider.php (in most cases a null provider should be fine)
- lang/en/aitool_YOURAITOOLPLUGINNAME.php
- version.php

Besides that, you basically need to implement two classes:
- classes/connector.php
- classes/instance.php

Required lang strings:
- `'adddescription'`: Put the description of your AI tool in this string. It will be shown as description of your connector in the connector selection modal when adding a new AI tool.

### 2.1 The connector class

This is the main class of the connector plugin implementing how the API of the external AI system is being accessed.

The `aitool_YOURAITOOLPLUGINNAME\connector` class has to extend the `\local_ai_manager\base_connector` class.

If you do not overwrite the constructor, an instance object of type `\aitool_YOURAITOOLPLUGINNAME\instance` will be injected automatically and will be accessible via `$this->instance`. See the next section to learn about what to do with this object ;-). 

Which methods need to be overwritten depends on how the API of your external AI system works. For example, if your AI system uses an OpenAI compatible API it's possible to just inherit from `\aitool_chatgpt\connector` and only override the `get_endpoint_url` method.

To understand what methods need to be overwritten, here comes a description of each method:

- `get_models_by_purpose(): array` (**abstract function, needs to be implemented**)

  Each connector plugin needs to declare which models are available and usable for which purposes and which purposes this connector plugin supports. It has to return an associative array where the key is the purpose (e.g., 'imggen') and the value is an array of model names supported for that purpose, see the other connectors for examples.

  The information given here is for example needed for the configuration interface: An instance with a model "dall-e-3" (an image generation model) for example won't be assignable to the purpose *singleprompt* in the purpose configuration page if the array part for *singleprompt' does not contain "dall-e-3": `'singleprompt' => ['gpt-4o']`

- `get_prompt_data(string $prompttext, \local_ai_manager\request_options $requestoptions): array` (**abstract function, needs to be implemented**)

  This method prepares the data payload for the API request. It takes the user's prompt text and request options as input and constructs an array of parameters to be sent to the AI service. The function needs to return the payload as an associative array which will be sent to the external API, see other connectors for examples.

- `execute_prompt_completion(StreamInterface $result, \local_ai_manager\request_options $requestoptions): \local_ai_manager\local\prompt_response` (**abstract function, needs to be implemented**)

  This method processes the response from the AI service. It decodes the JSON response, extracts the generated image data (base64 encoded), saves the image as a file in Moodle's file storage, and returns a `prompt_response` object containing the file URL and usage information.

- `get_headers(): array`

  Defines the headers to be sent with the API request in an associative array, for example `['Content-Type' => 'application/json']`. The base method already implements typical correct headers. Have a look at `\local_ai_manager\base_connector::get_headers` to see if you need to overwrite this method.

- `get_available_options(): array`

  Defines the configurable options available to the user for this connector. For example, it defines the available image sizes for DALL-E and GPT-image models. You are not completely free in which options can be used, this is being defined by the purpose you want your model to use. Have a look at the purpose class you want to use your model with. For examples, have a look at the connector classes `\aitool_googlesynthesize\connector` and `\aitool_dalle\connector` and the associated functions in the purpose classes `\aipurpose_tts\purpose::get_additional_purpose_options` and `\aipurpose_imggen\purpose::get_additional_purpose_options`.

- `get_custom_error_message(int $code, ?ClientExceptionInterface $exception = null): string`

  This function allows you to improve error messages. Based on the return status codes the `\local_ai_manager\manager` class already returns some general error messages to the user. However, sometimes APIs return additional information that developers want to extract from the response. In case you don't want to customize the message, return an empty string. If you want to customize the message, return the (localized) message as string. See \aitool_dalle\connector for an example.

- `get_unit()` (**abstract function, needs to be implemented**)

  Returns the unit which is being used by this connector, in this case `\local_ai_manager\local\unit::COUNT`. Currently, only two different units are supported: `\local_ai_manager\local\unit::COUNT` and `\local_ai_manager\local\unit::TOKEN`.

- `get_allowed_mimetypes(): array`

  Returns an array of allowed MIME types for the models that support images to be sent along a prompt.

- `has_customvalue1(): bool`

  Overwrite and return true if your connector uses a first custom value that should be stored as custom value in the request log table. **CARE: This part of the API is currently in a pretty strange state, so you better don't use it until this is fixed, sorry :)**

- `has_customvalue2(): bool`

  See `has_customvalue1()`.

Other methods of `\local_ai_manager\base_connector`:

- `get_models()`

  Helper function that just returns a list of all available model names without any grouping by purposes. It extracts the information out of `get_models_by_purpose()`.

### 2.2 The instance class

The instance class is basically a wrapper class for the configuration data and on the one hand, handles the corresponding record in `local_ai_manager_instance` while on the other hand, provides customization options for the frontend moodle form. It represents the *configuration instance* of a connector class. You can imagine this as a set of parameter values for the connector to use. That's also why a connector object will always have an instance object attached to it where it gets its configuration parameters from (API key, endpoint, etc.).

The `\aitool_YOURAITOOLPLUGINNAME\instance` class has to extend the `\local_ai_manager\base_instance` class. By overwriting some of the methods, a connector developer can customize the moodle form when adding an AI tool in the frontend.

Currently, there are also two traits (`\local_ai_manager\local\aitool_option_temperature` and `\local_ai_manager\local\aitool_option_azure`) providing some methods to avoid code duplication, because both the temperature parameter as well as the usage of Azure can be used by multiple connectors.

The table `local_ai_manager_instance` contains has five columns *customvalue1*, *customvalue2*, *customvalue3*, *customvalue4* and *customvalue5* of type text. Connector developers are free to use these columns to store and retrieve additional configuration options and make use of it from inside the connector class without having to define and use separate database tables for the connector plugin.

The class contains setter and getter for all the attributes that are mapped to the columns in the table `local_ai_manager_instance`.

If you do not need to provide any extra configuration parameters, you can just inherit from `\local_ai_manager\base_instance` and will not even have to overwrite a single method.

For further information have a look at the examples in the existing aitool plugins and the PhpDocs of the `\local_ai_manager\base_instance` class.

## 2.3 General recommendations

AI tools tend to provide OpenAI compatible APIs. In this case you probably want to create a separate connector plugin, but of course **you want to avoid code duplication as hard as possible*.

The following patterns could help achieve that:
- Instead of inheriting from `\local_ai_manager\base_connector` you also can inherit from `\aitool_chatgpt\connector` and just overwrite the necessary methods (e.g., `::get_endpoint_url`). Don't forget to declare the dependency on the plugin `aitool_chatgpt` in the `version.php` file in this case.
- You can also use a wrapper pattern like in `\aitool_telli\connector` to avoid code duplication. Don't forget to declare the dependencies on the used plugins in the `version.php` file in this case.

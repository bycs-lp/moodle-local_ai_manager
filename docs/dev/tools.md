# aitool subplugins

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

## 2. Write your own aitool subplugin

The subplugins of type *aitool* are located in the *local/ai_manager/tools/* directory. You can copy one of the existing subplugins and adapt it to your needs.

The following files should be self-explanatory:
- classes/privacy/provider.php (in most cases a null provider should be fine)
- lang/en/aitool_YOURAITOOLPLUGINNAME.php
- version.php

Besides that you basically need to implement two classes:
- classes/connector.php
- classes/instance.php

### 2.1 The connector class

The `aitool_YOURAITOOLPLUGINNAME\connector` class has to extend the `\local_ai_manager\base_connector` class. What methods need to be overwritten depends on how the API of your external AI system works. For example, if your AI system uses an OpenAI compatible API it's possible to just inherit from `\aitool_chatgpt\connector` and only override the `get_endpoint_url` method.

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

  Defines the configurable options available to the user for this connector. For example, it defines the available image sizes for DALL-E and GPT-image models. You are not completely free in which options can be used, this is being defined by the purpose you want your model to use. Have a look at the purpose class you want to use your model with. For examples, have a look at the connector classes `aitool_googlesynthesize\connector` and `aitool_dalle\connector` and the associated functions in the purpose classes `aipurpose_tts\purpose::get_additional_purpose_options` and `aipurpose_imggen\purpose::get_additional_purpose_options`.

- `get_custom_error_message(int $code, ?ClientExceptionInterface $exception = null): string`

  This function allows you to improve error messages. Based on the return status codes the `\local_ai_manager\manager` class already returns some general error messages to the user. However, sometimes APIs return additional information that developers want to extract from the response. Return a stringProvides customized error messages based on the HTTP status code and exception information received from the AI service.  This allows for more user-friendly error reporting (e.g., "content policy violation" for DALL-E).

- `get_unit()` (**abstract function, needs to be implemented**)

  Returns the unit which is being used by this connector, in this case `\local_ai_manager\local\unit::COUNT`. Currently, only two different units are supported: `\local_ai_manager\local\unit::COUNT` and `\local_ai_manager\local\unit::TOKEN`.


Other methods of `\local_ai_manager\base_connector`:

- `get_models()`

  Helper function that just returns a list of all available model names without any grouping by purposes. It extracts the information out of `get_models_by_purpose()`.
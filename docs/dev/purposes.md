# 2 aipurpose subplugins

## 2.1 General information (also for non-developers)

Whenever a call to an external AI system is being made, you need to specify which purpose you want to use. A purpose basically acts as a proxy between the frontend plugins and the connector plugins.

Currently implemented purposes are *chat*, *feedback*, *imggen* (image generation), *itt* (image to text), *questiongeneration*, *singleprompt*, $translate*, *tts* (text to speech). Every interaction with an external AI system needs to define which purpose it wants to use.

- *Option definitions*: The purpose is responsible for defining, sanitizing and providing additional options that are allowed to be sent along the prompt.
  For example, when using the purpose *itt* the purpose plugin defines that an option 'image' can be passed to the *perform_request* method that contains the base64 encoded image that should be passed to the external AI system. It also provides the option *allowed_mimetypes* to the "frontend" plugin so that the plugin sees what mimetypes are supported by the currently used external AI system.
- *Manipulating output*: The formatting of the output is also dependent from the used purpose. For example, the purpose *questiongeneration* takes care of formatting the output in a way that only the bare XML of a generated moodle question is being returned in the correct formatting (stripping additional blah blah of the LLM as well as for example markdown formatting, fixing encoding etc.).
- *Quota*: The user quota is bound to a certain purpose. That means for the basic role a quota of 50 *chat* requests per hour can be defined, for purpose *itt* it's just 10 requests per hour and purpose *imggen* is set to 0 requests per hour which means usage of this purpose is completely disabled for the role.
- *Access control*: By using an additional plugin *block_ai_control* (https://moodle.org/plugins/block_ai_control | https://github.com/bycs-lp/moodle-block_ai_control) you can allow teachers in a course to enable and disable the different purposes in their courses.
- *Statistics*: Statistics are being provided grouped by purposes, so you can tell for which the external AI systems are being used for.

## 2.2 Write your own aipurpose subplugin

The subplugins of type *aitool* are located in the *local/ai_manager/tools/* directory. You can copy one of the existing subplugins and adapt it to your needs.

### 2.2.1 Plugin structure

The following files should be self-explanatory:
- classes/privacy/provider.php (in most cases a null provider should be fine)
- lang/en/aipurpose_YOURAIPURPOSEPLUGINNAME.php
- version.php

Besides that, you only need to implement the following class:
- classes/purpose.php

### 2.2.2 Required lang strings:
- `'purposedescription'`: If you do not overwrite the method `::get_description` in your purpose class, the default implementation will try to get the string definition `'purposedescription'`. The purpose description will be shown to all the users in small info icons whenever a purpose is being shown (when assigning AI tools for a purpose, on the general `ai_info.php` page etc.). Put the description of your AI purpose in this string, especially what the purpose is for and when it is being used.
- `'requestcount'`: The string definition `'requestcount'` is used in the usage quota widget which basically display text like "5 chat, 3 image generation, 5 feedback requests". In this case "feedback requests" would be the lang string stored in the key `'requestcount'`.
- `'requestcount_shortened'`. Having a look at the example before, "chat" would be the lang string stored in the key `'requestcount_shortened'`.

### 2.2.3 The purpose class

The class `\aipurpose_YOURAIPURPOSEPLUGINNAME` has to extend `\local_ai_manager\base_purpose`. That's basically it, you don't need to do anything else, but of course you can and probably want to overwrite some methods of the base class. Here comes a description of the most important methods that could be overwritten:

- `get_additional_purpose_options(array $options): array`

  In this method your purpose can define allowed options that plugins using the local_ai_manager can pass along the prompt. It accepts the currently defined options and has to return the eventually manipulated `$options` array. The `$options` array is an associative array with the option name as key and a type as value. The type can be something like the constant `PARAM_TEXT` (or other moodle param types) or an array of possible values that can be handled in the frontend. As an example, have a look at `\aipurpose_itt\purpose::get_additional_request_options` or `\aipurpose_imggen\purpose::get_additional_request_options`.

  Often, the values of these options depend on the currently used connector. In this case you can fetch possible option values from the connector plugin. In this case you need to fetch possible options from the `::get_available_options` API function of the connector class of the connector plugin. As an example, see `\aipurpose_imggen\purpose::get_additional_request_options` in combination with `\aitool_dalle\connector::get_available_options` and `aitool_imagen\connector::get_available_options`.

- `get_additional_request_options(array $options): array`

  You can overwrite this method to manipulate the options that are being passed along the prompt. As an example see `\aipurpose_imggen\purpose::get_additional_request_options` where a free itemid is being passed to the options if the frontend plugin did not provide one.

- `format_output(string $output): string`

  If your purpose wants to apply some additional formatting of the output of the LLM you can overwrite this function, sanitize/manipulate/format the output accordingly and return the changed output. As an example see `\aipurpose_questiongeneration\purpose::format_output`.

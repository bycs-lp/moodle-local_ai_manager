# aipurpose subplugins

## 1. General information (also for non-developers)

Whenever a call to an external AI system is being made, you need to specify which purpose you want to use.

Currently implemented purposes are *chat*, *feedback*, *imggen* (image generation), *itt* (image to text), *questiongeneration*, *singleprompt*, $translate*, *tts* (text to speech). Every interaction with an external AI system needs to define which purpose it wants to use.

- *Option definitions*: The purpose is responsible for defining, sanitizing and providing additional options that are allowed to be sent along the prompt.
  For example, when using the purpose *itt* the purpose plugin defines that an option 'image' can be passed to the *perform_request* method that contains the base64 encoded image that should be passed to the external AI system. It also provides the option *allowed_mimetypes* to the "frontend" plugin so that the plugin sees what mimetypes are supported by the currently used external AI system.
- *Manipulating output*: The formatting of the output is also dependent from the used purpose. For example, the purpose *questiongeneration* takes care of formatting the output in a way that only the bare XML of a generated moodle question is being returned in the correct formatting (stripping additional blah blah of the LLM as well as for example markdown formatting, fixing encoding etc.).
- *Quota*: The user quota is bound to a certain purpose. That means for the basic role a quota of 50 *chat* requests per hour can be defined, for purpose *itt* it's just 10 requests per hour and purpose *imggen* is set to 0 requests per hour which means usage of this purpose is completely disabled for the role.
- *Access control*: By using an additional plugin *block_ai_control* (https://moodle.org/plugins/block_ai_control | https://github.com/bycs-lp/moodle-block_ai_control) you can allow teachers in a course to enable and disable the different purposes in their courses.
- *Statistics*: Statistics are being provided grouped by purposes, so you can tell for which the external AI systems are being used for.

## 2. Write your own aipurpose subplugin


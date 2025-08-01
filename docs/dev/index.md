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


### 2.5 Database structure and data models

The AI manager uses several database tables to manage its configuration and logging:

#### 2.5.1 Core tables

- **`local_ai_manager_instance`**: Stores connector instances (AI tool configurations)
  - Contains endpoint URLs, API keys, models, and custom fields for connector-specific settings
  - Each instance belongs to a specific tenant and represents a configured AI service
- **`local_ai_manager_config`**: Tenant-specific configuration storage
  - Key-value pairs for each tenant's settings (purpose configurations, quotas, etc.)
- **`local_ai_manager_userinfo`**: User-specific AI manager information
  - Stores role assignments, locked status, confirmation status, and usage scope
- **`local_ai_manager_userusage`**: Tracks current usage quotas per user and purpose
- **`local_ai_manager_request_log`**: Comprehensive logging of all AI requests
  - Includes prompts, responses, usage statistics, context information, and performance metrics

#### 2.5.2 Key data model classes

- **`\local_ai_manager\local\userinfo`**: Manages user roles (BASIC, EXTENDED, UNLIMITED) and access controls
- **`\local_ai_manager\local\userusage`**: Handles quota tracking and enforcement
- **`\local_ai_manager\local\tenant`**: Manages tenant identification and configuration
- **`\local_ai_manager\request_options`**: Encapsulates request parameters and sanitization
- **`\local_ai_manager\local\prompt_response`**: Wrapper for AI responses with usage statistics

### 2.6 Request flow and processing

The complete request flow involves multiple validation and processing steps:

1. **Manager instantiation**: Creates purpose and connector objects
2. **Permission checks**: Validates capabilities and context permissions
3. **Tenant validation**: Ensures tenant is enabled and configured
4. **User validation**: Checks if user is locked or has sufficient permissions
5. **Quota enforcement**: Validates current usage against configured limits
6. **Option sanitization**: Validates and sanitizes request options
7. **Request execution**: Performs the actual API call to external AI system
8. **Response processing**: Formats output through purpose-specific handlers
9. **Usage logging**: Records request details and usage statistics
10. **Event triggering**: Fires success/failure events for monitoring

## 3. Implementation guide for developers

### 3.1 Creating purpose subplugins

Purpose subplugins extend the `base_purpose` class and define how specific AI interactions should be handled. Each purpose plugin is located in `/local/ai_manager/purposes/{purposename}/`.

#### 3.1.1 Basic purpose structure

A minimal purpose plugin requires:

```
purposes/
  mypurpose/
    classes/
      purpose.php
    lang/
      en/
        aipurpose_mypurpose.php
    version.php
```

#### 3.1.2 Purpose implementation example

Here's how the `itt` (image to text) purpose handles image processing:

```php
namespace aipurpose_itt;

use local_ai_manager\base_purpose;
use local_ai_manager\local\connector_factory;
use local_ai_manager\local\userinfo;

class purpose extends base_purpose {

    #[\Override]
    public function get_additional_purpose_options(): array {
        global $USER;
        $userinfo = new userinfo($USER->id);
        $factory = \core\di::get(connector_factory::class);
        $connector = $factory->get_connector_by_purpose($this->get_plugin_name(), $userinfo->get_role());

        // Only add image option if current connector supports this purpose
        $instance = $connector->get_instance();
        if (!in_array($this->get_plugin_name(), $instance->supported_purposes())) {
            return [];
        }

        return [
            'image' => PARAM_RAW,
            'allowedmimetypes' => $this->get_allowed_mimetypes()
        ];
    }

    public function get_allowed_mimetypes(): array {
        // Retrieve allowed mimetypes from the current connector
        global $USER;
        $userinfo = new userinfo($USER->id);
        $factory = \core\di::get(connector_factory::class);
        $connector = $factory->get_connector_by_purpose($this->get_plugin_name(), $userinfo->get_role());

        if (!method_exists($connector, 'allowed_mimetypes') || empty($connector->allowed_mimetypes())) {
            throw new coding_exception('Connector does not declare allowed mimetypes');
        }
        return $connector->allowed_mimetypes();
    }
}
```

#### 3.1.3 Output formatting

Some purposes need to format AI responses. The `questiongeneration` purpose shows how to clean up LLM output:

```php
#[\Override]
public function format_output(string $output): string {
    // Remove Markdown code block wrappers
    $output = trim($output);
    $matches = [];
    $triplebackticks = "\u{0060}\u{0060}\u{0060}";
    preg_match('/' . $triplebackticks . '[a-zA-Z0-9]*\s*(.*?)\s*' . $triplebackticks . '/s', $output, $matches);
    if (count($matches) > 1) {
        $output = trim($matches[1]);
    }

    // Convert Unicode escape sequences to proper UTF-8
    return preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($matches) {
        return mb_convert_encoding(pack('H*', $matches[1]), 'UTF-8', 'UCS-2BE');
    }, $output);
}
```

#### 3.1.4 Critical implementation considerations for purposes

- **Option validation**: Always validate that the current connector supports your purpose before adding options
- **Security**: Sanitize all input data, especially when handling file uploads or user-generated content
- **Performance**: Consider caching allowed mimetypes and other connector-specific data
- **Error handling**: Provide meaningful error messages when connectors don't support required features
- **Internationalization**: All user-facing strings must be localizable

### 3.2 Creating connector (aitool) subplugins

Connector subplugins extend the `base_connector` class and handle communication with external AI services. Each connector is located in `/local/ai_manager/tools/{toolname}/`.

#### 3.2.1 Basic connector structure

```
tools/
  mytool/
    classes/
      connector.php
      instance.php
    lang/
      en/
        aitool_mytool.php
    version.php
```

#### 3.2.2 Connector implementation example

The ChatGPT connector demonstrates a complete implementation:

```php
namespace aitool_chatgpt;

use local_ai_manager\local\prompt_response;
use local_ai_manager\local\unit;
use local_ai_manager\local\usage;
use local_ai_manager\request_options;

class connector extends \local_ai_manager\base_connector {

    #[\Override]
    public function get_models_by_purpose(): array {
        $chatgptmodels = ['gpt-3.5-turbo', 'gpt-4-turbo', 'gpt-4o', 'gpt-4o-mini'];
        return [
            'chat' => $chatgptmodels,
            'feedback' => $chatgptmodels,
            'singleprompt' => $chatgptmodels,
            'translate' => $chatgptmodels,
            'itt' => ['gpt-4-turbo', 'gpt-4o', 'gpt-4o-mini'], // Only vision-capable models
            'questiongeneration' => $chatgptmodels,
        ];
    }

    #[\Override]
    public function get_unit(): unit {
        return unit::TOKEN; // This connector measures usage in tokens
    }

    #[\Override]
    public function get_prompt_data(string $prompttext, request_options $requestoptions): array {
        $options = $requestoptions->get_options();
        $messages = [];

        // Handle conversation context for chat purposes
        if (array_key_exists('conversationcontext', $options)) {
            foreach ($options['conversationcontext'] as $message) {
                $role = match($message['sender']) {
                    'user' => 'user',
                    'ai' => 'assistant',
                    'system' => 'system',
                    default => throw new \moodle_exception('exception_badmessageformat', 'local_ai_manager')
                };
                $messages[] = ['role' => $role, 'content' => $message['message']];
            }
            $messages[] = ['role' => 'user', 'content' => $prompttext];
        }
        // Handle image input for vision purposes
        else if (array_key_exists('image', $options)) {
            $messages[] = [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompttext],
                    ['type' => 'image_url', 'image_url' => ['url' => $options['image']]],
                ],
            ];
        }
        // Handle simple text prompts
        else {
            $messages[] = ['role' => 'user', 'content' => $prompttext];
        }

        $parameters = ['messages' => $messages];

        // Some models (like o1) don't support temperature
        if (!in_array($this->get_instance()->get_model(), ['o1', 'o1-mini'])) {
            $parameters['temperature'] = $this->instance->get_temperature();
        }

        // Set model unless using Azure (where it's preconfigured)
        if (!$this->instance->azure_enabled()) {
            $parameters['model'] = $this->instance->get_model();
        }

        return $parameters;
    }

    #[\Override]
    public function execute_prompt_completion(StreamInterface $result, request_options $requestoptions): prompt_response {
        $content = json_decode($result->getContents(), true);

        // Create usage object from API response
        $usage = new usage(
            (float) $content['usage']['total_tokens'],
            (float) $content['usage']['prompt_tokens'],
            (float) $content['usage']['completion_tokens']
        );

        return prompt_response::create_from_result(
            $content['model'],
            $usage,
            $content['choices'][0]['message']['content']
        );
    }

    #[\Override]
    public function allowed_mimetypes(): array {
        return ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];
    }

    #[\Override]
    protected function get_headers(): array {
        $headers = parent::get_headers();

        // Handle Azure OpenAI authentication differently
        if ($this->instance->azure_enabled()) {
            unset($headers['Authorization']);
            $headers['api-key'] = $this->instance->get_apikey();
        }

        return $headers;
    }
}
```

#### 3.2.3 Critical implementation considerations for connectors

- **Error handling**: Implement robust error handling with meaningful user messages
- **Authentication**: Support different authentication methods (API keys, OAuth, etc.)
- **Rate limiting**: Handle API rate limits gracefully
- **Model compatibility**: Clearly define which models support which purposes
- **Usage tracking**: Accurately report token/request usage for quota management
- **Security**: Never log sensitive data like API keys
- **Performance**: Implement appropriate timeouts and connection pooling

### 3.3 Using the AI manager in your plugins

#### 3.3.1 Basic usage pattern

```php
// Simple request
$manager = new \local_ai_manager\manager('singleprompt');
$response = $manager->perform_request(
    'Explain photosynthesis in simple terms',
    'mod_mymodule',
    $context->id
);

if ($response->get_code() === 200) {
    echo $response->get_content();
} else {
    echo 'Error: ' . $response->get_errormessage();
}
```

#### 3.3.2 Advanced usage with options

```php
// Request with image analysis
$manager = new \local_ai_manager\manager('itt');
$response = $manager->perform_request(
    'Describe what you see in this image',
    'mod_mymodule',
    $context->id,
    ['image' => 'data:image/jpeg;base64,' . base64_encode($imagedata)]
);

// Request with conversation context
$manager = new \local_ai_manager\manager('chat');
$response = $manager->perform_request(
    'What is the capital of France?',
    'mod_mymodule',
    $context->id,
    [
        'conversationcontext' => [
            ['sender' => 'user', 'message' => 'Hello'],
            ['sender' => 'ai', 'message' => 'Hello! How can I help you today?'],
        ]
    ]
);
```

#### 3.3.3 JavaScript usage

```javascript
// Import the AI manager module
import AiManager from 'local_ai_manager/make_request';

// Make a request
const response = await AiManager.make_request(
    'singleprompt',
    'Tell me a joke about programming',
    'mod_mymodule',
    contextId,
    {}
);

if (response.success) {
    console.log(response.content);
} else {
    console.error('Error:', response.errormessage);
}
```

### 3.4 Permission and capability system

#### 3.4.1 Required capabilities

- **`local/ai_manager:use`**: Basic capability to use AI tools
- **`local/ai_manager:manage`**: Manage tenant configuration (tenant managers)
- **`local/ai_manager:managetenants`**: Manage all tenants (super admins)
- **`local/ai_manager:viewstatistics`**: View usage statistics
- **`local/ai_manager:deleteconversations`**: Delete conversation data

#### 3.4.2 Role-based access control

The system uses internal roles that map to Moodle capabilities:

```php
// User roles
userinfo::ROLE_BASIC     // Limited quota, basic features
userinfo::ROLE_EXTENDED  // Higher quota, more features
userinfo::ROLE_UNLIMITED // No quota limits, all features
```

#### 3.4.3 Permission checking example

```php
// Check if user can use AI in context
if (!has_capability('local/ai_manager:use', $context)) {
    throw new moodle_exception('nopermission');
}

// Check if user is tenant manager
$userinfo = new \local_ai_manager\local\userinfo($USER->id);
if ($userinfo->get_role() === \local_ai_manager\local\userinfo::ROLE_UNLIMITED) {
    // User can manage tenant settings
}
```

### 3.5 Quota and usage management

#### 3.5.1 Quota configuration

Quotas are configured per purpose and role:

```php
// Get quota for a user and purpose
$userusage = new \local_ai_manager\local\userusage($userid, 'chat');
$currentusage = $userusage->get_currentusage();
$maxusage = $configmanager->get_max_requests('chat', $userinfo->get_role());

if ($currentusage >= $maxusage) {
    // User has exceeded quota
}
```

#### 3.5.2 Usage tracking

The system automatically tracks usage, but you can also query it:

```php
// Get detailed usage statistics
$requestlog = new \local_ai_manager\local\request_log();
$usage = $requestlog->get_usage_statistics($userid, $starttime, $endtime);
```

### 3.6 Event system

The AI manager fires events that you can observe:

```php
// Listen for successful AI responses
$observer = function(\local_ai_manager\event\get_ai_response_succeeded $event) {
    $eventdata = $event->get_data();
    // Log successful usage, update UI, etc.
};

// Listen for failed AI responses
$observer = function(\local_ai_manager\event\get_ai_response_failed $event) {
    $eventdata = $event->get_data();
    // Handle errors, notify admins, etc.
};
```

### 3.7 Testing and debugging

#### 3.7.1 Debugging requests

Enable detailed logging by setting debug level:

```php
// In config.php for development
$CFG->debug = DEBUG_DEVELOPER;
$CFG->debugdisplay = 1;
```

All requests are logged to `local_ai_manager_request_log` with full request/response data.

#### 3.7.2 Unit testing

Create PHPUnit tests for your purposes and connectors:

```php
class my_purpose_test extends \advanced_testcase {

    public function test_purpose_options() {
        $this->resetAfterTest();

        $purpose = new \aipurpose_mypurpose\purpose();
        $options = $purpose->get_additional_purpose_options();

        $this->assertArrayHasKey('expectedoption', $options);
    }
}
```

## 4. Advanced implementation topics

### 4.1 Tenant management implementation

The tenant system is a core feature that requires careful implementation. Tenants are identified through user profile fields and affect nearly every aspect of the system.

#### 4.1.1 Tenant identification

```php
// Get tenant for current user
$tenant = \core\di::get(\local_ai_manager\local\tenant::class);
$tenantidentifier = $tenant->get_identifier();

// Check if current tenant is enabled
$configmanager = \core\di::get(\local_ai_manager\local\config_manager::class);
if (!$configmanager->is_tenant_enabled()) {
    // Tenant is disabled
}
```

#### 4.1.2 Tenant-specific configuration

All configurations are stored per tenant in the `local_ai_manager_config` table:

```php
// Set tenant-specific configuration
$configmanager->set_config('purpose_chat_tool_basic', 'instance_id_123', $tenantidentifier);

// Get tenant-specific configuration
$toolid = $configmanager->get_config('purpose_chat_tool_basic', $tenantidentifier);
```

#### 4.1.3 Cross-tenant access control

**CRITICAL**: Tenant managers can only access their own tenant's data unless they have the `local/ai_manager:managetenants` capability:

```php
// Check if user can manage all tenants
if (has_capability('local/ai_manager:managetenants', context_system::instance())) {
    // Can access any tenant via URL parameter
    $tenant = optional_param('tenant', '', PARAM_TEXT);
} else {
    // Can only access own tenant
    $tenant = \core\di::get(\local_ai_manager\local\tenant::class)->get_identifier();
}
```

### 4.2 Security considerations

#### 4.2.1 API key management

API keys are stored encrypted and should never be logged:

```php
// In connector implementation - NEVER log API keys
public function make_request(array $data, request_options $requestoptions): request_response {
    // API key is automatically handled by base class
    // Do not add it to any debug output or logs

    debugging('Making request with data: ' . json_encode($data)); // OK
    debugging('API key: ' . $this->get_api_key()); // NEVER DO THIS
}
```

#### 4.2.2 Input sanitization

All user inputs must be properly sanitized:

```php
// In purpose implementation
public function get_additional_purpose_options(): array {
    return [
        'usertext' => PARAM_TEXT,           // Safe text
        'userhtml' => PARAM_CLEANHTML,     // Clean HTML
        'image' => PARAM_RAW,              // Raw data for base64 images
        'numbers' => PARAM_INT,            // Integer values
        'conversationcontext' => self::PARAM_ARRAY  // Array of conversation messages
    ];
}
```

#### 4.2.3 Content filtering

Some AI providers have content filters that can block requests:

```php
// Handle content filter errors in connector
protected function get_custom_error_message(int $code, ?ClientExceptionInterface $exception = null): string {
    if ($code === 400 && method_exists($exception, 'getResponse')) {
        $responsebody = json_decode($exception->getResponse()->getBody()->getContents());
        if (property_exists($responsebody, 'error') &&
            property_exists($responsebody->error, 'code') &&
            $responsebody->error->code === 'content_filter') {
            return get_string('err_contentfilter', 'aitool_chatgpt');
        }
    }
    return '';
}
```

### 4.3 Performance optimization

#### 4.3.1 Request caching

Consider implementing caching for repeated requests:

```php
// Example caching strategy (not implemented in core, but recommended for heavy usage)
$cachekey = sha1($prompttext . serialize($options));
$cache = cache::make('local_ai_manager', 'responses');

if ($cachedresponse = $cache->get($cachekey)) {
    return $cachedresponse;
}

$response = $this->connector->make_request($data, $requestoptions);
$cache->set($cachekey, $response, 3600); // Cache for 1 hour
```

#### 4.3.2 Database query optimization

The system is designed to minimize database queries:

```php
// Efficient user info loading
$userinfo = new \local_ai_manager\local\userinfo($userid);
// This loads all user data in one query

// Efficient configuration access
$configmanager = \core\di::get(\local_ai_manager\local\config_manager::class);
// Configuration is cached per request
```

#### 4.3.3 Connection pooling

Use HTTP client best practices:

```php
// In base_connector
public function make_request(array $data, request_options $requestoptions): request_response {
    $client = new http_client([
        'timeout' => get_config('local_ai_manager', 'requesttimeout'),
        'verify' => !empty(get_config('local_ai_manager', 'verifyssl')),
        // Connection pooling is handled by Guzzle automatically
    ]);
}
```

### 4.4 Error handling patterns

#### 4.4.1 Graceful degradation

Always provide meaningful error messages to users:

```php
// In manager class
try {
    $response = $this->connector->make_request($data, $requestoptions);
} catch (\Exception $exception) {
    return prompt_response::create_from_error(
        500,
        get_string('error_internalerror', 'local_ai_manager'),
        $exception->getMessage()
    );
}
```

#### 4.4.2 Retry mechanisms

Implement retry logic for temporary failures:

```php
// Example retry pattern for connector implementations
private function make_request_with_retry(array $data, int $maxretries = 3): request_response {
    $attempts = 0;
    do {
        try {
            return $this->make_request($data);
        } catch (\Exception $exception) {
            $attempts++;
            if ($attempts >= $maxretries) {
                throw $exception;
            }
            sleep(pow(2, $attempts)); // Exponential backoff
        }
    } while ($attempts < $maxretries);
}
```

### 4.5 Monitoring and logging

#### 4.5.1 Request logging

All requests are automatically logged to `local_ai_manager_request_log`:

```php
// Access request logs
$requestlog = new \local_ai_manager\local\request_log();

// Get usage statistics
$stats = $requestlog->get_usage_by_purpose($starttime, $endtime);
$userstats = $requestlog->get_usage_by_user($userid, $starttime, $endtime);
```

#### 4.5.2 Performance monitoring

Track request duration and success rates:

```php
// In your connector implementation
public function execute_prompt_completion(StreamInterface $result, request_options $requestoptions): prompt_response {
    $starttime = microtime(true);

    // Process response
    $content = json_decode($result->getContents(), true);

    $duration = microtime(true) - $starttime;

    // Duration is automatically logged by the manager
    return prompt_response::create_from_result($model, $usage, $content, $duration);
}
```

### 4.6 Internationalization (i18n)

#### 4.6.1 Language strings structure

All user-facing strings must be localized:

```php
// In lang/en/aipurpose_mypurpose.php
$string['pluginname'] = 'My Purpose';
$string['purposedescription'] = 'This purpose is used for...';
$string['error_invalidinput'] = 'The input provided is not valid';

// In lang/en/aitool_mytool.php
$string['pluginname'] = 'My AI Tool';
$string['apikey'] = 'API Key';
$string['endpoint'] = 'Endpoint URL';
$string['err_connectionfailed'] = 'Connection to AI service failed';
```

#### 4.6.2 Multi-language support

Consider language-specific prompts and responses:

```php
// In purpose implementation
public function get_localized_prompt_prefix(): string {
    $lang = current_language();
    switch ($lang) {
        case 'de':
            return 'Antworten Sie auf Deutsch: ';
        case 'fr':
            return 'Répondez en français: ';
        default:
            return 'Respond in English: ';
    }
}
```

### 4.7 Plugin integration patterns

#### 4.7.1 Block integration example

```php
// In block_myblock/block_myblock.php
public function get_content() {
    if (has_capability('local/ai_manager:use', $this->page->context)) {
        try {
            $manager = new \local_ai_manager\manager('singleprompt');
            $response = $manager->perform_request(
                'Generate a summary of this course',
                'block_myblock',
                $this->page->context->id
            );

            if ($response->get_code() === 200) {
                $this->content->text = $response->get_content();
            } else {
                $this->content->text = get_string('ai_error', 'block_myblock');
            }
        } catch (\Exception $e) {
            $this->content->text = get_string('ai_unavailable', 'block_myblock');
        }
    }

    return $this->content;
}
```

#### 4.7.2 Activity module integration

```php
// In mod_mymodule/lib.php
function mymodule_generate_feedback($submissiontext, $context) {
    // Check if AI is available
    if (!has_capability('local/ai_manager:use', $context)) {
        return null;
    }

    $manager = new \local_ai_manager\manager('feedback');
    $prompt = "Provide constructive feedback on this submission: " . $submissiontext;

    $response = $manager->perform_request(
        $prompt,
        'mod_mymodule',
        $context->id
    );

    return $response->get_code() === 200 ? $response->get_content() : null;
}
```

### 4.8 Webhook and callback handling

#### 4.8.1 Asynchronous processing

For long-running AI tasks, consider asynchronous processing:

```php
// Example pattern for async processing
class process_ai_request extends \core\task\adhoc_task {

    public function execute() {
        $data = $this->get_custom_data();

        $manager = new \local_ai_manager\manager($data->purpose);
        $response = $manager->perform_request(
            $data->prompt,
            $data->component,
            $data->contextid,
            $data->options
        );

        // Store result or trigger callback
        $this->handle_response($response, $data);
    }
}

// Queue the task
$task = new process_ai_request();
$task->set_custom_data($requestdata);
\core\task\manager::queue_adhoc_task($task);
```

### 4.9 Data privacy and GDPR compliance

#### 4.9.1 Data retention policies

Implement automatic cleanup of old request logs:

```php
// Example cleanup task
class cleanup_old_logs extends \core\task\scheduled_task {

    public function execute() {
        global $DB;

        $retentiondays = get_config('local_ai_manager', 'log_retention_days');
        $cutoff = time() - ($retentiondays * 24 * 60 * 60);

        $DB->delete_records_select(
            'local_ai_manager_request_log',
            'timecreated < ? AND deleted = 0',
            [$cutoff]
        );
    }
}
```

#### 4.9.2 User data export

Provide user data for GDPR export requests:

```php
// In privacy/provider.php
public static function export_user_data(approved_contextlist $contextlist) {
    global $DB;

    $user = $contextlist->get_user();

    // Export AI request history
    $requests = $DB->get_records('local_ai_manager_request_log', ['userid' => $user->id]);

    foreach ($requests as $request) {
        // Export request data (anonymized)
        $data = [
            'purpose' => $request->purpose,
            'timestamp' => transform::datetime($request->timecreated),
            'tokens_used' => $request->value,
        ];

        writer::with_context($context)->export_data(['ai_requests'], (object)$data);
    }
}
```

## 5. Admin settings and configuration

### 5.1 Global settings

The AI manager provides several global settings that affect the entire system:

#### 5.1.1 Core settings

- **`local_ai_manager/tenantcolumn`**: Database field used for tenant identification ('institution' or 'department')
- **`local_ai_manager/requesttimeout`**: Timeout for AI API requests in seconds (default: 30)
- **`local_ai_manager/verifyssl`**: Whether to verify SSL certificates for AI API calls
- **`local_ai_manager/log_retention_days`**: How long to keep request logs (for GDPR compliance)

#### 5.1.2 Setting implementation example

```php
// In settings.php
$settings->add(new admin_setting_configselect(
    'local_ai_manager/tenantcolumn',
    get_string('tenantcolumn', 'local_ai_manager'),
    get_string('tenantcolumn_desc', 'local_ai_manager'),
    'institution',
    ['institution' => get_string('institution'), 'department' => get_string('department')]
));

$settings->add(new admin_setting_configtext(
    'local_ai_manager/requesttimeout',
    get_string('requesttimeout', 'local_ai_manager'),
    get_string('requesttimeout_desc', 'local_ai_manager'),
    30,
    PARAM_INT
));
```

### 5.2 Tenant configuration

Each tenant can have completely different configurations:

#### 5.2.1 Purpose configuration per tenant

```php
// Example: Configure which AI tool to use for each purpose and role
foreach (['chat', 'feedback', 'singleprompt'] as $purpose) {
    foreach ([userinfo::ROLE_BASIC, userinfo::ROLE_EXTENDED] as $role) {
        $configkey = base_purpose::get_purpose_tool_config_key($purpose, $role);
        $configmanager->set_config($configkey, $selectedtoolid, $tenant);
    }
}
```

#### 5.2.2 Quota configuration per tenant

```php
// Set quota limits per purpose and role
$quotakey = 'max_requests_' . $purpose . '_' . userinfo::get_role_as_string($role);
$configmanager->set_config($quotakey, $maxrequests, $tenant);

// Set time window for quota (hourly, daily, weekly)
$windowkey = 'quota_window_' . $purpose . '_' . userinfo::get_role_as_string($role);
$configmanager->set_config($windowkey, 'hourly', $tenant);
```

### 5.3 Instance (AI tool) configuration

#### 5.3.1 Creating connector instances

Each AI tool can have multiple instances with different configurations:

```php
// Create a new connector instance
$instance = new \local_ai_manager\local\instance();
$instance->set_name('ChatGPT 4o Creative');
$instance->set_tenant($tenant);
$instance->set_connector('chatgpt');
$instance->set_endpoint('https://api.openai.com/v1/chat/completions');
$instance->set_apikey($apikey);
$instance->set_model('gpt-4o');
$instance->set_customfield1('temperature:0.8'); // High creativity
$instance->save();
```

#### 5.3.2 Azure OpenAI configuration example

```php
// Configure for Azure OpenAI
$instance->set_endpoint('https://myresource.openai.azure.com/openai/deployments/gpt-4o/chat/completions?api-version=2024-02-01');
$instance->set_customfield1('azure_enabled:1');
$instance->set_customfield2('temperature:0.3'); // Low creativity for precise tasks
```

### 5.4 User management and roles

#### 5.4.1 Role assignment

```php
// Assign role to user
$userinfo = new \local_ai_manager\local\userinfo($userid);
$userinfo->set_role(\local_ai_manager\local\userinfo::ROLE_EXTENDED);
$userinfo->save();

// Lock user from using AI
$userinfo->set_locked(true);
$userinfo->save();
```

#### 5.4.2 Scope limitation

```php
// Restrict user to course contexts only
$userinfo->set_scope(\local_ai_manager\local\userinfo::SCOPE_COURSES_ONLY);
$userinfo->save();
```

## 6. Best practices and patterns

### 6.1 Purpose plugin best practices

#### 6.1.1 Dynamic option validation

Always validate that the current connector supports your purpose:

```php
public function get_additional_purpose_options(): array {
    global $USER;
    $userinfo = new userinfo($USER->id);
    $factory = \core\di::get(connector_factory::class);

    try {
        $connector = $factory->get_connector_by_purpose($this->get_plugin_name(), $userinfo->get_role());
        $instance = $connector->get_instance();

        // Check if connector supports this purpose
        if (!in_array($this->get_plugin_name(), $instance->supported_purposes())) {
            return []; // Return empty options if not supported
        }

        return $this->get_purpose_specific_options($connector);
    } catch (\Exception $e) {
        debugging('Could not load connector for purpose: ' . $e->getMessage());
        return [];
    }
}
```

#### 6.1.2 Robust output formatting

Handle various AI response formats gracefully:

```php
public function format_output(string $output): string {
    $output = trim($output);

    // Remove common AI response wrappers
    $patterns = [
        '/```[a-zA-Z0-9]*\s*(.*?)\s*```/s',  // Code blocks
        '/\*\*(.*?)\*\*/s',                  // Bold text
        '/^\s*[\-\*\+]\s*/m',                // List items
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $output, $matches) && count($matches) > 1) {
            $output = trim($matches[1]);
            break;
        }
    }

    // Handle encoding issues
    $output = $this->fix_encoding($output);

    // Validate output format for this purpose
    if (!$this->validate_output_format($output)) {
        throw new \moodle_exception('invalid_ai_output', 'aipurpose_' . $this->get_plugin_name());
    }

    return $output;
}

private function fix_encoding(string $text): string {
    // Convert Unicode escape sequences
    $text = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($matches) {
        return mb_convert_encoding(pack('H*', $matches[1]), 'UTF-8', 'UCS-2BE');
    }, $text);

    // Fix common encoding issues
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

    return $text;
}
```

### 6.2 Connector plugin best practices

#### 6.2.1 Robust authentication handling

Support multiple authentication methods:

```php
protected function get_headers(): array {
    $headers = parent::get_headers();

    // Handle different authentication methods
    if ($this->instance->is_azure_enabled()) {
        unset($headers['Authorization']);
        $headers['api-key'] = $this->instance->get_apikey();
    } elseif ($this->instance->uses_bearer_auth()) {
        $headers['Authorization'] = 'Bearer ' . $this->instance->get_apikey();
    } else {
        // Default OpenAI-style auth
        $headers['Authorization'] = 'Bearer ' . $this->instance->get_apikey();
    }

    // Add custom headers if needed
    if ($customheaders = $this->instance->get_custom_headers()) {
        $headers = array_merge($headers, $customheaders);
    }

    return $headers;
}
```

#### 6.2.2 Model compatibility matrix

Define clear model-purpose compatibility:

```php
public function get_models_by_purpose(): array {
    $textmodels = ['gpt-3.5-turbo', 'gpt-4', 'gpt-4-turbo', 'gpt-4o'];
    $visionmodels = ['gpt-4-turbo', 'gpt-4o']; // Only these support vision
    $reasoningmodels = ['o1', 'o1-mini']; // Different parameter support

    return [
        'chat' => $textmodels,
        'feedback' => $textmodels,
        'singleprompt' => array_merge($textmodels, $reasoningmodels),
        'translate' => $textmodels,
        'itt' => $visionmodels,
        'questiongeneration' => $textmodels,
        // Image generation would use different models
        'imggen' => [], // This connector doesn't support image generation
    ];
}
```

#### 6.2.3 Error handling with context

Provide detailed error information:

```php
protected function get_custom_error_message(int $code, ?ClientExceptionInterface $exception = null): string {
    $message = '';

    switch ($code) {
        case 400:
            if ($this->is_content_filter_error($exception)) {
                $message = get_string('err_contentfilter', $this->get_component_name());
            } elseif ($this->is_invalid_model_error($exception)) {
                $message = get_string('err_invalidmodel', $this->get_component_name(), $this->get_instance()->get_model());
            }
            break;

        case 401:
            $message = get_string('err_unauthorized', $this->get_component_name());
            break;

        case 429:
            $message = get_string('err_ratelimit', $this->get_component_name());
            break;

        case 503:
            $message = get_string('err_serviceoverloaded', $this->get_component_name());
            break;
    }

    return $message;
}

private function is_content_filter_error(?ClientExceptionInterface $exception): bool {
    if (!$exception || !method_exists($exception, 'getResponse')) {
        return false;
    }

    $response = json_decode($exception->getResponse()->getBody()->getContents(), true);
    return isset($response['error']['code']) && $response['error']['code'] === 'content_filter';
}
```

### 6.3 Frontend integration patterns

#### 6.3.1 Progressive enhancement with JavaScript

```javascript
// Example: Gradual AI feature enhancement
import AiManager from 'local_ai_manager/make_request';

export class AiEnhancedTextarea {
    constructor(textareaElement, purpose, contextId) {
        this.textarea = textareaElement;
        this.purpose = purpose;
        this.contextId = contextId;
        this.init();
    }

    async init() {
        // Check if AI is available
        if (await this.checkAiAvailability()) {
            this.addAiButtons();
        }
    }

    async checkAiAvailability() {
        try {
            // Test with a minimal request
            const response = await AiManager.make_request(
                this.purpose,
                'test',
                'mod_mymodule',
                this.contextId,
                {}
            );
            return response.success || response.code !== 403; // 403 = no permission
        } catch (error) {
            return false;
        }
    }

    addAiButtons() {
        const buttonContainer = document.createElement('div');
        buttonContainer.className = 'ai-controls';

        const improveButton = this.createButton('Improve text', () => this.improveText());
        const summarizeButton = this.createButton('Summarize', () => this.summarizeText());

        buttonContainer.appendChild(improveButton);
        buttonContainer.appendChild(summarizeButton);

        this.textarea.parentNode.insertBefore(buttonContainer, this.textarea.nextSibling);
    }

    async improveText() {
        const text = this.textarea.value;
        if (!text.trim()) return;

        this.showLoading();

        try {
            const response = await AiManager.make_request(
                'feedback',
                `Please improve this text: ${text}`,
                'mod_mymodule',
                this.contextId,
                {}
            );

            if (response.success) {
                this.showSuggestion(response.content);
            } else {
                this.showError(response.errormessage);
            }
        } catch (error) {
            this.showError('AI service is currently unavailable');
        } finally {
            this.hideLoading();
        }
    }
}
```

#### 6.3.2 Accessible AI integration

Ensure AI features are accessible:

```javascript
// Accessible AI suggestion implementation
showSuggestion(suggestion) {
    const suggestionBox = document.createElement('div');
    suggestionBox.setAttribute('role', 'region');
    suggestionBox.setAttribute('aria-label', 'AI suggestion');
    suggestionBox.className = 'ai-suggestion';

    suggestionBox.innerHTML = `
        <h3>AI Suggestion</h3>
        <div class="suggestion-content">${this.escapeHtml(suggestion)}</div>
        <div class="suggestion-actions">
            <button type="button" onclick="this.acceptSuggestion()" aria-describedby="accept-help">
                Accept
            </button>
            <button type="button" onclick="this.dismissSuggestion()">
                Dismiss
            </button>
        </div>
        <div id="accept-help" class="sr-only">
            This will replace your current text with the AI suggestion
        </div>
    `;

    // Announce to screen readers
    this.announceToScreenReader('AI suggestion is ready');

    this.textarea.parentNode.appendChild(suggestionBox);
}
```

### 6.4 Performance optimization patterns

#### 6.4.1 Lazy loading of AI features

```php
// Only load AI manager when needed
class LazyAiManager {
    private ?manager $manager = null;
    private string $purpose;

    public function __construct(string $purpose) {
        $this->purpose = $purpose;
    }

    private function getManager(): manager {
        if ($this->manager === null) {
            $this->manager = new manager($this->purpose);
        }
        return $this->manager;
    }

    public function isAvailable(): bool {
        try {
            $this->getManager();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function performRequest(string $prompt, string $component, int $contextid, array $options = []): ?prompt_response {
        if (!$this->isAvailable()) {
            return null;
        }

        return $this->getManager()->perform_request($prompt, $component, $contextid, $options);
    }
}
```

#### 6.4.2 Batch processing for multiple requests

```php
// Process multiple AI requests efficiently
class BatchAiProcessor {
    private array $requests = [];

    public function addRequest(string $purpose, string $prompt, string $component, int $contextid, array $options = []): string {
        $requestid = uniqid();
        $this->requests[$requestid] = [
            'purpose' => $purpose,
            'prompt' => $prompt,
            'component' => $component,
            'contextid' => $contextid,
            'options' => $options
        ];
        return $requestid;
    }

    public function processAll(): array {
        $results = [];

        // Group by purpose for efficiency
        $groupedRequests = $this->groupRequestsByPurpose();

        foreach ($groupedRequests as $purpose => $requests) {
            try {
                $manager = new manager($purpose);

                foreach ($requests as $requestid => $request) {
                    $results[$requestid] = $manager->perform_request(
                        $request['prompt'],
                        $request['component'],
                        $request['contextid'],
                        $request['options']
                    );
                }
            } catch (\Exception $e) {
                // Mark all requests in this group as failed
                foreach ($requests as $requestid => $request) {
                    $results[$requestid] = prompt_response::create_from_error(
                        500,
                        'Batch processing failed: ' . $e->getMessage(),
                        ''
                    );
                }
            }
        }

        return $results;
    }

    private function groupRequestsByPurpose(): array {
        $grouped = [];
        foreach ($this->requests as $requestid => $request) {
            $grouped[$request['purpose']][$requestid] = $request;
        }
        return $grouped;
    }
}
```

## 7. Migration and upgrade considerations

### 7.1 Database migration patterns

When updating database schemas, follow Moodle's upgrade patterns:

```php
// In db/upgrade.php
function xmldb_local_ai_manager_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2024073001) {
        // Add new field to existing table
        $table = new xmldb_table('local_ai_manager_instance');
        $field = new xmldb_field('customfield6', XMLDB_TYPE_TEXT, null, null, null, null, null, 'customfield5');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2024073001, 'local', 'ai_manager');
    }

    if ($oldversion < 2024073002) {
        // Migrate existing data to new format
        $instances = $DB->get_records('local_ai_manager_instance');
        foreach ($instances as $instance) {
            if (!empty($instance->oldfield)) {
                $DB->set_field('local_ai_manager_instance', 'customfield6',
                    $instance->oldfield, ['id' => $instance->id]);
            }
        }

        upgrade_plugin_savepoint(true, 2024073002, 'local', 'ai_manager');
    }

    return true;
}
```

### 7.2 Configuration migration

When configuration formats change:

```php
// Migrate old configuration format to new format
class ConfigMigrator {
    public static function migrateToNewFormat($oldversion) {
        $configmanager = \core\di::get(\local_ai_manager\local\config_manager::class);

        if ($oldversion < 2024073000) {
            // Migrate purpose configurations
            $tenants = $configmanager->get_all_tenants();

            foreach ($tenants as $tenant) {
                $oldconfig = $configmanager->get_config('ai_tools', $tenant);
                if ($oldconfig) {
                    $newconfig = self::transformConfig($oldconfig);
                    foreach ($newconfig as $key => $value) {
                        $configmanager->set_config($key, $value, $tenant);
                    }
                    // Remove old config
                    $configmanager->unset_config('ai_tools', $tenant);
                }
            }
        }
    }

    private static function transformConfig($oldconfig) {
        // Transform old format to new format
        $newconfig = [];
        $olddata = json_decode($oldconfig, true);

        foreach ($olddata as $purpose => $toolid) {
            $newconfig["purpose_{$purpose}_tool_basic"] = $toolid;
            $newconfig["purpose_{$purpose}_tool_extended"] = $toolid;
        }

        return $newconfig;
    }
}
```

## 8. Testing and quality assurance

### 8.1 Unit testing patterns

```php
// Test purpose functionality
class AipurposeTestCase extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
    }

    public function test_purpose_options_validation() {
        $purpose = new \aipurpose_itt\purpose();

        // Mock connector that supports ITT
        $this->create_mock_connector('mockconnector', ['itt']);
        $this->configure_purpose_tool('itt', 'mockconnector');

        $options = $purpose->get_additional_purpose_options();

        $this->assertArrayHasKey('image', $options);
        $this->assertArrayHasKey('allowedmimetypes', $options);
        $this->assertEquals(PARAM_RAW, $options['image']);
    }

    public function test_purpose_without_supported_connector() {
        $purpose = new \aipurpose_itt\purpose();

        // Mock connector that doesn't support ITT
        $this->create_mock_connector('textonly', ['chat', 'singleprompt']);
        $this->configure_purpose_tool('itt', 'textonly');

        $options = $purpose->get_additional_purpose_options();

        // Should return empty array when connector doesn't support purpose
        $this->assertEmpty($options);
    }

    private function create_mock_connector(string $name, array $supported_purposes) {
        // Helper method to create test connectors
        global $DB;

        $record = new \stdClass();
        $record->name = 'Test ' . $name;
        $record->tenant = '';
        $record->connector = $name;
        $record->endpoint = 'https://test.example.com';
        $record->apikey = 'test-key';
        $record->model = 'test-model';
        $record->timecreated = time();
        $record->timemodified = time();

        return $DB->insert_record('local_ai_manager_instance', $record);
    }
}
```

### 8.2 Integration testing

```php
// Test full request flow
class ManagerIntegrationTest extends \advanced_testcase {

    public function test_full_request_flow() {
        $this->resetAfterTest(true);

        // Setup test environment
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);

        $this->setUser($user);

        // Create test instance
        $instanceid = $this->create_test_instance();
        $this->configure_purpose_tool('singleprompt', $instanceid);

        // Mock the HTTP response
        $this->mock_ai_response();

        // Perform request
        $manager = new \local_ai_manager\manager('singleprompt');
        $response = $manager->perform_request(
            'Test prompt',
            'mod_test',
            $context->id
        );

        // Verify response
        $this->assertEquals(200, $response->get_code());
        $this->assertNotEmpty($response->get_content());

        // Verify logging
        $this->assert_request_logged($user->id, 'singleprompt');
    }

    private function mock_ai_response() {
        // Mock HTTP client to return test response
        // Implementation depends on testing framework
    }
}
```

## 9. Deployment and maintenance

### 9.1 Production deployment checklist

- [ ] Configure SSL certificate verification (`verifyssl` setting)
- [ ] Set appropriate request timeouts
- [ ] Configure log retention policies for GDPR compliance
- [ ] Set up monitoring for API usage and costs
- [ ] Configure appropriate user roles and quotas
- [ ] Test all AI connectors with production API keys
- [ ] Verify tenant configuration and access controls
- [ ] Set up backup procedures for configuration data



## 3. Admin settings
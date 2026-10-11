# Providers

`HtmlTranslator` takes any `TranslationProviderInterface`. For language models, use `LlmTranslationProvider` with a
chat model adapter (ADR-0005): the prompt, the response parsing and the corrective retries are the same for every
model, and the adapter only maps one HTTP call.

| Provider | Class | Notes |
|---|---|---|
| OpenAI | `OpenAiCompatibleChatModel` + `OpenAiCompatibleConfig::openAi()` | JSON mode, `max_completion_tokens` |
| Gemini | `OpenAiCompatibleChatModel` + `OpenAiCompatibleConfig::gemini()` | Gemini's OpenAI-compatible endpoint |
| Ollama | `OpenAiCompatibleChatModel` + `OpenAiCompatibleConfig::ollama()` | local, no key |
| vLLM | `OpenAiCompatibleChatModel` + `OpenAiCompatibleConfig::vllm()` | local, optional key |
| LM Studio | `OpenAiCompatibleChatModel` + `OpenAiCompatibleConfig::lmStudio()` | local, no JSON mode (LM Studio only supports JSON schemas) |
| Other OpenAI-compatible servers | `OpenAiCompatibleChatModel` + `new OpenAiCompatibleConfig(...)` | |
| Anthropic | planned (0.3) | |
| No network (tests, demos) | `PseudoLocalizationProvider` | deterministic fake translation |

## OpenAI-compatible APIs

`OpenAiCompatibleChatModel` calls `POST {baseUrl}/chat/completions` through any PSR-18 client and PSR-17 factories
(ADR-0002). With Symfony HttpClient, `Psr18Client` is all three:

```php
use HtmlTranslator\HtmlTranslator;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\OpenAiCompatibleChatModel;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\OpenAiCompatibleConfig;
use HtmlTranslator\Provider\Llm\LlmTranslationProvider;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

$http = new Psr18Client(HttpClient::create(['timeout' => 60]));
$model = new OpenAiCompatibleChatModel($http, $http, $http, OpenAiCompatibleConfig::openAi($_ENV['OPENAI_API_KEY'], 'gpt-4o-mini'));

$translator = new HtmlTranslator(new LlmTranslationProvider($model));
```

With Guzzle and `nyholm/psr7` (or any other PSR-17 implementation):

```php
$factory = new Nyholm\Psr7\Factory\Psr17Factory();
$model = new OpenAiCompatibleChatModel(
    new GuzzleHttp\Client(['timeout' => 60]),
    $factory,
    $factory,
    OpenAiCompatibleConfig::ollama('llama3.1'),
);
```

### Configuration

| Preset | Base URL | Authentication | JSON mode | Output limit field |
|---|---|---|---|---|
| `openAi($apiKey, $model)` | `https://api.openai.com/v1` | `Authorization: Bearer` | yes | `max_completion_tokens` |
| `gemini($apiKey, $model)` | `https://generativelanguage.googleapis.com/v1beta/openai` | `Authorization: Bearer` | yes | `max_tokens` |
| `ollama($model, $baseUrl = 'http://localhost:11434/v1')` | local | none | yes | `max_tokens` |
| `vllm($model, $baseUrl = 'http://localhost:8000/v1', $apiKey = null)` | local | optional Bearer | yes | `max_tokens` |
| `lmStudio($model, $baseUrl = 'http://localhost:1234/v1')` | local | none | no | `max_tokens` |

For any other server, create the configuration directly:

```php
new OpenAiCompatibleConfig(
    name: 'together',                       // prefix of modelId(), e.g. "together:meta-llama/…"
    baseUrl: 'https://api.together.xyz/v1',
    model: 'meta-llama/Llama-3.3-70B-Instruct-Turbo',
    apiKey: $_ENV['TOGETHER_API_KEY'],      // null for servers without authentication
    jsonMode: true,                         // send response_format json_object
    maxTokensField: OpenAiCompatibleConfig::MAX_TOKENS,
    extraHeaders: [],                       // e.g. ['OpenAI-Organization' => 'org-…']; treated as secret
);
```

- **Timeouts** are set on the HTTP client: PSR-18 has no per-request timeout. Translation batches can take a while,
  so allow at least 60 seconds.
- **Retries** for rate limits and server errors are not built in yet (0.4). Until then, a client with its own retry
  middleware works. `LlmTranslationProvider` asks the model once more only when an answer cannot be parsed.
- **The API key and extra header values** are treated as secrets: they are only sent as headers, never quoted in
  validation errors, removed from error messages built by the adapter (also from a transport error's causes), hidden
  from `var_dump()`, `print_r()` and `var_export()`, and the configuration cannot be serialized. The key must be
  visible ASCII without spaces or line breaks, so trim a key read from a file. A kept transport exception
  (`getPrevious()`) may still hold the HTTP request, including its `Authorization` header: do not log its request.
- **The base URL** must not contain credentials, a query or a fragment.
- **Errors** are `ProviderException`s. HTTP errors carry `statusCode` and `vendorCode` (the vendor's `error.code`,
  `error.type` or Gemini's `status`). A refused or content-filtered batch is not an exception: its units are reported
  as issues and handled by the fallback strategy.

## Writing your own

- **A chat model for another API**: implement `Provider\Llm\ChatModelInterface` and pass
  `Provider\Llm\Testing\ChatModelContractTestCase` (it needs `phpunit/phpunit`).
- **A non-LLM engine**: implement `Provider\TranslationProviderInterface` and pass
  `Provider\Testing\TranslationProviderContractTestCase`.

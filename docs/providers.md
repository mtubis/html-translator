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
| Anthropic | `AnthropicChatModel` + `AnthropicConfig` | Claude Messages API, structured output (JSON schema) |
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
| `openAi($apiKey, $model, $jsonMode = JsonMode::JsonSchema)` | `https://api.openai.com/v1` | `Authorization: Bearer` | `JsonSchema` | `max_completion_tokens` |
| `gemini($apiKey, $model)` | `https://generativelanguage.googleapis.com/v1beta/openai` | `Authorization: Bearer` | `JsonObject` | `max_tokens` |
| `ollama($model, $baseUrl = 'http://localhost:11434/v1')` | local | none | `JsonObject` | `max_tokens` |
| `vllm($model, $baseUrl = 'http://localhost:8000/v1', $apiKey = null)` | local | optional Bearer | `JsonObject` | `max_tokens` |
| `lmStudio($model, $baseUrl = 'http://localhost:1234/v1')` | local | none | `JsonSchemaOnly` | `max_tokens` |

For any other server, create the configuration directly:

```php
new OpenAiCompatibleConfig(
    name: 'together',                       // prefix of modelId(), e.g. "together:meta-llama/…"
    baseUrl: 'https://api.together.xyz/v1',
    model: 'meta-llama/Llama-3.3-70B-Instruct-Turbo',
    apiKey: $_ENV['TOGETHER_API_KEY'],      // null for servers without authentication
    jsonMode: JsonMode::JsonObject,         // see JSON mode below
    maxTokensField: OpenAiCompatibleConfig::MAX_TOKENS,
    extraHeaders: [],                       // e.g. ['OpenAI-Organization' => 'org-…']; treated as secret
);
```

- **JSON mode** (`OpenAiCompatible\JsonMode`) decides how a request that asks for JSON (`ChatRequest::$jsonResponse`)
  is sent as `response_format`:

  | Mode | Request with a schema | Request without a schema |
  |---|---|---|
  | `None` | nothing | nothing |
  | `JsonObject` | `{"type": "json_object"}` | `{"type": "json_object"}` |
  | `JsonSchema` | `{"type": "json_schema", "json_schema": {"name": "answer", "strict": true, "schema": …}}` | `{"type": "json_object"}` |
  | `JsonSchemaOnly` | as `JsonSchema` | nothing (for servers that reject `json_object`, e.g. LM Studio) |

  `LlmTranslationProvider` always sends its answer schema (ADR-0014), so with `JsonSchema` the answer shape is
  guaranteed. Strict mode requires every property to be listed in `required` and `additionalProperties: false`; the
  provider's schema follows these rules. OpenAI models without structured outputs (e.g. `gpt-3.5-turbo`,
  `gpt-4-turbo`) reject `json_schema` with HTTP 400: use `openAi($key, $model, JsonMode::JsonObject)`. Gemini, Ollama and vLLM stay on `JsonObject` until their schema support is
  verified; a server without JSON support needs `None`, and the prompt still asks for JSON.
- **Timeouts** are set on the HTTP client: PSR-18 has no per-request timeout. Translation batches can take a while,
  so allow at least 60 seconds.
- **Retries**: see [Retries](#retries) below. `LlmTranslationProvider` also asks the model once more when an answer
  cannot be parsed.
- **The API key and extra header values** are treated as secrets: they are only sent as headers, never quoted in
  validation errors, removed from error messages built by the adapter (also from a transport error's causes), hidden
  from `var_dump()`, `print_r()` and `var_export()`, and the configuration cannot be serialized. The key must be
  visible ASCII without spaces or line breaks, so trim a key read from a file. The client's transport exception
  is never kept: PSR-18 request and network exceptions hold the HTTP request with the key header, and any exception
  thrown while sending recorded the request among its trace arguments. `getPrevious()` is a `ProviderException` with
  the client exception's type and redacted message, and parameters that receive secrets are `#[\SensitiveParameter]`,
  so dumps of the exceptions show no key even with `zend.exception_ignore_args` off. A client that breaks PSR-18 by
  throwing other exceptions is not covered.
- **The base URL** must not contain credentials, a query or a fragment.
- **Errors** are `ProviderException`s. HTTP errors carry `statusCode` and `vendorCode` (the vendor's `error.code`,
  `error.type` or Gemini's `status`). A refused or content-filtered batch is not an exception: its units are reported
  as issues and handled by the fallback strategy.

## Anthropic

`AnthropicChatModel` calls the Claude Messages API (`POST {baseUrl}/messages`, `anthropic-version: 2023-06-01`)
through any PSR-18 client and PSR-17 factories:

```php
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicChatModel;
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicConfig;

$http = new Psr18Client(HttpClient::create(['timeout' => 180]));
$model = new AnthropicChatModel($http, $http, $http, new AnthropicConfig(
    model: 'claude-opus-5-5',
    apiKey: $_ENV['ANTHROPIC_API_KEY'],
));

$translator = new HtmlTranslator(new LlmTranslationProvider($model));
```

### Configuration

| Parameter | Default | Meaning |
|---|---|---|
| `model` | — (required) | Model id, e.g. `claude-opus-5-5` or `claude-sonnet-5-5` |
| `apiKey` | — (required) | Sent as `x-api-key`; treated as a secret like the OpenAI-compatible key |
| `baseUrl` | `https://api.anthropic.com/v1` | API root, e.g. a gateway in front of the Claude API |
| `apiVersion` | `2023-06-01` | The `anthropic-version` header |
| `defaultMaxOutputTokens` | `16000` | `max_tokens` when a request sets none (the API requires a limit) |
| `effort` | `null` (model default) | `AnthropicEffort::Low` … `Max` (`low`, `medium`, `high`, `xhigh`, `max`): thinking depth and token spend |
| `refusalFallbacks` | `false` | Let the API answer a refused request with a fallback model (`fallbacks: "default"`) |
| `extraHeaders` | `[]` | Further headers, e.g. `Authorization` for a gateway; treated as secret |

- **JSON**: `LlmTranslationProvider` asks for its answer format as a JSON schema, which the adapter sends as structured
  output (`output_config.format`), so the answer is always valid JSON (ADR-0014). Current Claude models reject assistant
  prefill; the adapter never uses it.
- **Thinking**: current models think before answering (Claude Opus 5.5 always does); the adapter returns only the text
  blocks. Lower `effort` (e.g. `low`) for cheaper and faster translations, raise it for hard texts. Requests can take a
  while at higher effort: allow a generous client timeout (120 seconds or more).
- **Temperature**: current Claude models reject sampling parameters with HTTP 400; the adapter's 0–1 range only
  matters for older models. `LlmTranslationProvider` sends none.
- **Prefill**: a conversation that ends with an assistant message is rejected before the call
  (`InvalidArgumentException`), since current models answer it with HTTP 400.
- **Refusals**: a declined request (`stop_reason: "refusal"`) becomes `FinishReason::Refusal`; its units are reported
  as permanent failures. With `refusalFallbacks: true` the adapter sends the `server-side-fallback-2026-07-01` beta,
  and the API may answer with a fallback model instead (`ChatResponse::$model` names it). Available on the Claude API
  only; check that your model supports it.
- **Errors** are `ProviderException`s with `statusCode` and `vendorCode` (`error.type`, e.g. `rate_limit_error`,
  `overloaded_error` for HTTP 529); the message names the request id, which Anthropic support asks for. Transient
  failures are retried, see [Retries](#retries).

## Retries

Both adapters retry transient failures through a `Retrier` (ARCHITECTURE §6.8):

- **What:** HTTP 408, 429, 500, 502, 503, 504 and 529 (Anthropic's overload), and PSR-18 network errors. Permanent
  errors such as 400, 401 or 404 are never retried.
- **How often:** 3 attempts in total by default (at most 10). A request whose body stream cannot be rewound is sent
  once.
- **How long:** the server's `Retry-After` (seconds, or an HTTP date in GMT measured against its `Date` header) when it
  sends one, otherwise exponential backoff with jitter: 0.5–1 s before the second attempt, 1–2 s before the third, …
  Every wait, including a longer `Retry-After`, is capped at the maximum delay (30 s by default, at most one hour). A
  call therefore waits at most `(attempts − 1) × maximum delay`, plus `attempts ×` the client timeout for the requests.
- **Cost:** a 5xx or a network error can come after the API already ran and billed the request, so a retry may pay for
  it twice. Pass `new RetryPolicy(retryNetworkErrors: false)` or `RetryPolicy::none()` when that matters more than
  availability.
- **Messages:** a failure after several attempts says so (`… returned HTTP 529 after 3 attempts …`); status and vendor
  code are those of the last response.

```php
use HtmlTranslator\Provider\Llm\Bridge\Retrier;
use HtmlTranslator\Provider\Llm\Bridge\RetryPolicy;

// More patience for a busy model:
$model = new AnthropicChatModel($http, $http, $http, $config, new Retrier(new RetryPolicy(maxAttempts: 5, maxDelayMilliseconds: 60_000)));

// No retries, e.g. when the HTTP client already retries:
$model = new OpenAiCompatibleChatModel($http, $http, $http, $config, new Retrier(RetryPolicy::none()));
```

Waiting goes through the `SleeperInterface` port (default `UsleepSleeper`, which blocks); event-loop applications can
pass their own, and tests `Provider\Llm\Testing\FakeSleeper`, which records the delays: `new Retrier(sleeper: $sleeper)`.

## Writing your own

- **A chat model for another API**: implement `Provider\Llm\ChatModelInterface` and pass
  `Provider\Llm\Testing\ChatModelContractTestCase` (it needs `phpunit/phpunit`).
  Since 0.4 its scenarios include `ChatScenarioKind::NetworkError`: the fake transport throws a PSR-18
  `NetworkExceptionInterface` holding the request. The adapter must name the transport message, and neither its
  exception chain nor the arguments recorded in its traces may hold the request, a client exception or the key.
  Send through `Provider\Llm\Bridge\Retrier` for transport retries: `send()` returns a `RetriedResponse` (the last
  response and the number of attempts) and throws a `TransportFailure` when the client fails; turn that into a
  `ProviderException` at once without keeping it, since its previous exception holds the request.
- **A non-LLM engine**: implement `Provider\TranslationProviderInterface` and pass
  `Provider\Testing\TranslationProviderContractTestCase`. Report tokens in `BatchResult::$usage` (`TokenUsage`) when the engine
  reports them; leave it null otherwise (engines billed by characters too, since the stats count tokens).
  To let `HtmlTranslator` reuse its translations from a unit cache, implement
  `Provider\CacheableProviderInterface` instead: `cacheId()` names everything that changes the output (engine, model,
  settings), e.g. `deepl:formal`, never a credential and never per-batch data such as locales (ADR-0016). Without it the
  provider works, but nothing is cached. A decorator that changes translations prefixes the wrapped provider's id with
  its own settings; one around a provider that is not cacheable does not implement the interface.
- **Chat model settings and the cache**: `ChatModelInterface::modelId()` is part of the cache key, so it names every
  setting that changes answers (`AnthropicConfig` adds `+effort=<level>` and `+fallbacks`).

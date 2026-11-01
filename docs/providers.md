# Providers

`HtmlTranslator` takes any `TranslationProviderInterface`. For language models, use `LlmTranslationProvider` with a
chat model adapter (ADR-0005): the prompt, the response parsing and the corrective retries are the same for every
model, and the adapter only maps one HTTP call.

| Provider | Class | Notes |
|---|---|---|
| OpenAI | `OpenAiCompatibleChatModel` + `OpenAiCompatibleConfig::openAi()` | structured output (JSON schema), `max_completion_tokens` |
| Gemini | `OpenAiCompatibleChatModel` + `OpenAiCompatibleConfig::gemini()` | Gemini's OpenAI-compatible endpoint, structured output (JSON schema) |
| Ollama | `OpenAiCompatibleChatModel` + `OpenAiCompatibleConfig::ollama()` | local, no key, structured output (JSON schema) |
| vLLM | `OpenAiCompatibleChatModel` + `OpenAiCompatibleConfig::vllm()` | local, optional key, structured output (JSON schema) |
| LM Studio | `OpenAiCompatibleChatModel` + `OpenAiCompatibleConfig::lmStudio()` | local, JSON schema only (LM Studio rejects `json_object`) |
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

| Preset | Base URL | Authentication | JSON mode | Output limit field | Token usage |
|---|---|---|---|---|---|
| `openAi($apiKey, $model, $jsonMode = JsonMode::JsonSchema)` | `https://api.openai.com/v1` | `Authorization: Bearer` | `JsonSchema` | `max_completion_tokens` | reported |
| `gemini($apiKey, $model, $jsonMode = JsonMode::JsonSchema)` | `https://generativelanguage.googleapis.com/v1beta/openai` | `Authorization: Bearer` | `JsonSchema` | `max_tokens` | reported |
| `ollama($model, $baseUrl = 'http://localhost:11434/v1', $jsonMode = JsonMode::JsonSchema)` | local | none | `JsonSchema` | `max_tokens` | reported |
| `vllm($model, $baseUrl = 'http://localhost:8000/v1', $apiKey = null, $jsonMode = JsonMode::JsonSchema)` | local | optional Bearer | `JsonSchema` | `max_tokens` | reported |
| `lmStudio($model, $baseUrl = 'http://localhost:1234/v1', $jsonMode = JsonMode::JsonSchemaOnly)` | local | none | `JsonSchemaOnly` | `max_tokens` | reported |

Every preset takes another JSON mode as its last parameter, for a server version or model that does not support the
default. The defaults were checked on 2026-11-01 against live servers, with `composer test:integration` and several
`tools/eval` runs over the golden fixtures in both modes (#80):

| Preset | Checked with | Result |
|---|---|---|
| `openAi()` | OpenAI API, `gpt-4o-mini` | `json_schema` and `json_object` both 20/20 in every run |
| `gemini()` | Gemini API, `gemini-3.8-flash` | both modes accepted (no HTTP 400); the runs were cut short by the free tier's quota (HTTP 429), so the answer quality per mode is not compared |
| `ollama()` | Ollama 0.40.3, `llama3.1` | `json_schema` 17, 16, 18 of 20; `json_object` 19, 16, 15 of 20 |
| `vllm()` | vLLM 0.32.0, `Qwen/Qwen3-0.6B` | `json_schema` 15, 13, 15 of 20; `json_object` 14, 13, 14 of 20 (the small model cuts long answers off in both modes) |
| `lmStudio()` | LM Studio 0.4.28, `qwen/qwen3-4b-2507` | `json_schema_only` 20/20 in every run; `json_object` rejected with HTTP 400 |

For any other server, create the configuration directly:

```php
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\JsonMode;

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

  `LlmTranslationProvider` always sends its answer schema (ADR-0014). With `JsonSchema`, OpenAI enforces the answer
  shape; other servers constrain decoding to the schema as far as they support it, and a server that ignores the
  schema still gets the format from the prompt. Every answer is parsed and validated either way, and an unreadable
  one is asked for again. ADR-0014 names only OpenAI and LM Studio as schema users; since #80 the table above is the
  current list. Strict mode requires every property to be listed in `required` and `additionalProperties: false`; the
  provider's schema follows these rules. OpenAI models without structured outputs (e.g. `gpt-3.5-turbo`,
  `gpt-4-turbo`) reject `json_schema` with HTTP 400: use `openAi($key, $model, JsonMode::JsonObject)`. Only the
  server versions in the table above were checked; for an older Ollama, vLLM or Gemini model without structured
  outputs, pass the mode by name: `ollama($model, jsonMode: JsonMode::JsonObject)`,
  `vllm($model, jsonMode: JsonMode::JsonObject)` or `gemini($key, $model, JsonMode::JsonObject)`. A server without
  JSON support needs `None`, and the prompt still asks for JSON.
- **Token usage** is read from the response's `usage.prompt_tokens` and `usage.completion_tokens` and summed in
  `TranslationStats::$inputTokens` / `$outputTokens`. Every preset reported it in the eval runs of 2026-11-01 (#80);
  the integration tests fail when a preset stops reporting it. A server that sends no `usage` leaves the counts
  `null` rather than 0.
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
  `error.type` or Gemini's `status`; a code that is not a single plain line of at most 100 characters is dropped).
  `usage` holds the tokens a failing response reported. A status outside 100–599 from a non-compliant PSR-7
  implementation gives an exception without `statusCode`. A refused or content-filtered batch is not an exception:
  `LlmTranslationProvider` sends it again in halves to find the refused units (at most 2 × ⌈log2 n⌉ further calls for
  n units; `isolateRefusals: false` turns this off), and only those are reported as issues and handled by the fallback
  strategy ([ADR-0019](adr/0019-isolating-refused-units.md)).

## Anthropic

`AnthropicChatModel` calls the Claude Messages API (`POST {baseUrl}/messages`, `anthropic-version: 2023-06-01`)
through any PSR-18 client and PSR-17 factories:

```php
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicChatModel;
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicConfig;

$http = new Psr18Client(HttpClient::create(['timeout' => 180]));
$config = new AnthropicConfig(model: 'claude-opus-5-5', apiKey: $_ENV['ANTHROPIC_API_KEY']);
$model = new AnthropicChatModel($http, $http, $http, $config);

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
| `betas` | `[]` | Further `anthropic-beta` values, e.g. `['some-feature-2026-01-01']`; see Betas below |

- **JSON**: `LlmTranslationProvider` asks for its answer format as a JSON schema, which the adapter sends as structured
  output (`output_config.format`), so the answer is always valid JSON (ADR-0014). Current Claude models reject assistant
  prefill; the adapter never uses it.
- **Thinking**: current models think before answering (Claude Opus 5.5 always does); the adapter returns only the text
  blocks. Lower `effort` (e.g. `low`) for cheaper and faster translations, raise it for hard texts. Requests can take a
  while at higher effort: allow a generous client timeout (120 seconds or more).
- **Token usage** is read from `usage.input_tokens` and `usage.output_tokens`; the Messages API always reports it,
  and the integration tests fail when it is missing.
- **Temperature**: current Claude models reject sampling parameters with HTTP 400; the adapter's 0–1 range only
  matters for older models. `LlmTranslationProvider` sends none.
- **Prefill**: a conversation that ends with an assistant message is rejected before the call
  (`InvalidArgumentException`), since current models answer it with HTTP 400.
- **Refusals**: a declined request (`stop_reason: "refusal"`) becomes `FinishReason::Refusal`; `LlmTranslationProvider`
  sends the batch again in halves to find the refused units, and only those are reported as permanent failures
  ([ADR-0019](adr/0019-isolating-refused-units.md)). With `refusalFallbacks: true` the adapter sends the `server-side-fallback-2026-07-01` beta,
  and the API may answer with a fallback model instead (`ChatResponse::$model` names it). Available on the Claude API
  only; check that your model supports it.
- **Betas**: `betas` enables beta features without a library release. Each value must be in the form of Anthropic's beta
  names (lowercase letters, digits, `.`, `_` and `-`); they are sent with the fallback beta in one comma-separated `anthropic-beta` header, without duplicates.
  `anthropic-beta` cannot be set through `extraHeaders`. A beta may change request or response shapes the adapter
  does not map (new content blocks are ignored, new fields are not sent), so test a beta before relying on it. Betas
  are part of `modelId()` (`+betas=…`), since they may change answers.
- **Errors** are `ProviderException`s with `statusCode`, `vendorCode` (`error.type`, e.g. `rate_limit_error`,
  `overloaded_error` for HTTP 529) and `usage`, as for OpenAI-compatible servers; the message names the request id,
  which Anthropic support asks for. Transient failures are retried, see [Retries](#retries).

## Configuration from arrays

The configs can be built from YAML or PHP arrays, as the Symfony bundle and the Laravel package do. Array arguments
are checked at runtime: a wrong element throws `InvalidArgumentException`, never a `TypeError`, naming the parameter
and the key (or, for secrets, only the position) and the type of the value, never the value itself.

| Argument | Expected |
|---|---|
| `extraHeaders` (both configs) | header name → string value; a wrong value is reported as `extraHeaders entry #2 must be a string, got int.` without the header name |
| `AnthropicConfig` `betas` | a list of strings; a map such as `['x' => 'beta']` is rejected (`betas must be a list, got key "x".`) |
| `RetryPolicy` `retryableStatuses` | a list of ints from 400 to 599; the string `"503"` from configuration is rejected |

Scalar settings (model, base URL, key) are typed parameters: convert them from configuration before passing them, e.g.
with `(int)` for a limit read from an environment variable.

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
$model = new OpenAiCompatibleChatModel($http, $http, $http, OpenAiCompatibleConfig::ollama('llama3.1'), new Retrier(RetryPolicy::none()));
```

Waiting goes through the `SleeperInterface` port (default `UsleepSleeper`, which blocks); event-loop applications can
pass their own, and tests `Provider\Llm\Testing\FakeSleeper`, which records the delays: `new Retrier(sleeper: $sleeper)`.

## Writing your own

A chat model for another API (`ChatModelInterface`) and a non-LLM engine or decorator (`TranslationProviderInterface`,
`CacheableProviderInterface`) are covered in the [extension guide](extending.md), with their contract tests. For chat
models, `modelId()` is part of the cache key, so it names every setting that changes answers, as `AnthropicConfig`
does with `+effort=<level>`, `+betas=<sorted list>` and `+fallbacks`.

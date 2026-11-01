# Extending html-translator

The extension points are the ports below and the test doubles and contract test cases in the `Testing` namespaces.
They are covered by the backward compatibility promise
([ADR-0018](adr/0018-public-api-and-backward-compatibility-promise.md)) like the public API: an interface does not
gain methods within a major version.

| Port | Implement it for | Contract test case |
|---|---|---|
| `Provider\TranslationProviderInterface` | a non-LLM engine, or a decorator around another provider | `Provider\Testing\TranslationProviderContractTestCase` |
| `Provider\CacheableProviderInterface` | a provider whose translations may come from the unit cache | the same |
| `Provider\Llm\ChatModelInterface` | another LLM API, used through `LlmTranslationProvider` | `Provider\Llm\Testing\ChatModelContractTestCase` |
| `Batching\TokenEstimatorInterface` | exact token counts for batching | – |
| `Provider\Llm\Bridge\SleeperInterface` | waiting between retries without blocking (event loops) | – |

The contract test cases are PHPUnit test cases: they need `phpunit/phpunit` in your `require-dev` (it is a suggested
dependency, not a required one). Every snippet of this guide runs in the documentation test suite, the contract tests
included.

## A provider of your own

`HtmlTranslator` sends batches of units to a `TranslationProviderInterface` and gets a `BatchResult` back. A unit's
text has numbered pseudo-tags for its inline markup (`Click <g1>here</g1><x2/>`), and `&`, `<` and `>` escaped. The
translation must keep every pseudo-tag exactly once and add no other markup; the core validates it and sends a broken
unit again with a correction. The interface's docblock has the full rules. In short:

- **Failures**: a unit the provider could not translate goes into `BatchResult::$failures` with a reason (it is sent
  again); one that can never be translated (a content filter) into `$permanentFailures`. A batch that cannot be
  translated at all throws `ProviderException`; wrap transport and decoding errors in it, since callers rely on every
  exception implementing `TranslatorException`.
- **Credentials** never go into reasons, exception messages or traces.
- **Tokens** of every call go into `BatchResult::$usage` (`TokenUsage`); leave it null when the engine reports none.
- **Batches and results are checked**: `TranslationBatch` and `BatchResult` throw `InvalidArgumentException` for a
  wrong element (a `units` array with gaps from `array_filter()` needs `array_values()`; a translation decoded from
  JSON may be an int). That is a bug in the provider, not a `ProviderException`: convert what an engine sends first.
- **Caching**: implement `CacheableProviderInterface` to let `HtmlTranslator` reuse translations from a PSR-6 pool
  (ADR-0016). `cacheId()` names everything that changes the output (engine, model, settings, e.g. `deepl:formal`),
  never a credential and never per-batch data such as locales or the profile. Without it the provider works, but
  nothing is cached.

A decorator is the smallest provider. This one sets Polish quotation marks in the translations of another provider;
it is cacheable only around a cacheable provider, and its id composes its own settings with the wrapped one's:

```php
use HtmlTranslator\Provider\BatchResult;
use HtmlTranslator\Provider\CacheableProviderInterface;
use HtmlTranslator\Provider\TranslationBatch;

final readonly class PolishQuotesProvider implements CacheableProviderInterface
{
    public function __construct(
        private CacheableProviderInterface $inner,
    ) {}

    public function translate(TranslationBatch $batch): BatchResult
    {
        $result = $this->inner->translate($batch);
        if ('pl' !== $batch->locales->target->language) {
            return $result;
        }

        // Unit text escapes only &, < and >, so quotation marks are plain characters; pseudo-tags have none.
        $translations = array_map(
            static fn(string $text): string => preg_replace('/"([^"<>]*)"/u', '„$1”', $text) ?? $text,
            $result->translations,
        );

        return new BatchResult($translations, $result->failures, $result->permanentFailures, $result->usage);
    }

    public function cacheId(): string
    {
        return 'polish-quotes:v1:' . $this->inner->cacheId();
    }
}
```

```php
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\HtmlTranslator;
use HtmlTranslator\Provider\Testing\PseudoLocalizationProvider;

$translator = new HtmlTranslator(new PolishQuotesProvider(new PseudoLocalizationProvider()));

echo $translator->translate('<p>Click "Save" to keep your changes.</p>', Locale::fromString('pl'))->html();
```

```text
<p>[!! Çļíçķ „Šáṽé” ţö ķééþ ýöüŕ çĥáñĝéš. !!]</p>
```

Its contract test only says how to build the provider. `provider()` is required. A provider with a service of its own
also overrides `unavailableProvider()` (configured so that the service cannot be reached) and
`providerWithUndecodableAnswers()`; their tests are skipped otherwise. Override `locales()` for an engine that does not
translate English to Polish, and `detectsTheSourceLanguage()` for one that needs a source language:

```php
use HtmlTranslator\Provider\Testing\TranslationProviderContractTestCase;
use HtmlTranslator\Provider\TranslationProviderInterface;

final class PolishQuotesProviderContractTest extends TranslationProviderContractTestCase
{
    protected function provider(): TranslationProviderInterface
    {
        return new PolishQuotesProvider(new PseudoLocalizationProvider());
    }
}
```

## A chat model for another API

For an LLM, implement `ChatModelInterface` instead and pass it to `LlmTranslationProvider`: the prompt, the response
parsing, the corrective retries and the refusal isolation are shared, so an adapter only maps one HTTP call. It

- sends through PSR-18 and PSR-17 (ADR-0002), with `Provider\Llm\Bridge\Retrier` for transport retries;
- turns a `TransportFailure` into a `ProviderException` at once, without keeping it: its previous exception holds the
  request with the key header;
- throws `ProviderException::fromHttpResponse()` for HTTP errors, with the vendor's code and the tokens a failing
  response reports (`usage:`), and a `ProviderException` for bodies it cannot read: every failure is a
  `ProviderException`, so encode requests so that they cannot fail (substitute invalid UTF-8) and check every field
  of an answer before building a `ChatResponse`;
- maps the vendor's finish reason to `FinishReason`, keeps a token count the server does not send `null`, and drops
  negative and non-integer counts (`TokenUsage` rejects them); a wrapper that rethrows a `ProviderException` with more
  tokens uses `withUsage()`;
- keeps the key out of messages, traces and dumps (`#[\SensitiveParameter]`, `\SensitiveParameterValue`);
- names every setting that changes answers in `modelId()`, which is part of the cache key.

The adapter for Acme Chat, a made-up API:

```php
use HtmlTranslator\Exception\ProviderException;
use HtmlTranslator\Exception\UntrustedText;
use HtmlTranslator\Provider\Llm\Bridge\Retrier;
use HtmlTranslator\Provider\Llm\Bridge\TransportFailure;
use HtmlTranslator\Provider\Llm\ChatMessage;
use HtmlTranslator\Provider\Llm\ChatModelInterface;
use HtmlTranslator\Provider\Llm\ChatRequest;
use HtmlTranslator\Provider\Llm\ChatResponse;
use HtmlTranslator\Provider\Llm\FinishReason;
use HtmlTranslator\Provider\TokenUsage;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * POST {baseUrl}/chat with {"model", "messages", "temperature", "max_tokens", "json"}; the answer is
 * {"model", "text", "stop", "usage": {"in", "out"}}, an error {"error": {"code", "message"}}.
 */
final readonly class AcmeChatModel implements ChatModelInterface
{
    private \SensitiveParameterValue $apiKey;

    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requests,
        private StreamFactoryInterface $streams,
        private string $model,
        #[\SensitiveParameter] string $apiKey,
        private string $baseUrl = 'https://api.acme.test/v1',
        private Retrier $retrier = new Retrier(),
    ) {
        // Wrapped, so var_dump() or print_r() of the adapter, or of a translator holding it, does not show the key.
        $this->apiKey = new \SensitiveParameterValue($apiKey);
    }

    public function modelId(): string
    {
        return 'acme:' . $this->model;
    }

    public function complete(ChatRequest $request): ChatResponse
    {
        $body = json_encode([
            'model' => $this->model,
            'messages' => array_map(static fn(ChatMessage $message): array => ['role' => $message->role->value, 'content' => $message->content], $request->messages),
            'temperature' => $request->temperature,
            'max_tokens' => $request->maxOutputTokens,
            'json' => $request->jsonResponse,
        ], \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_UNESCAPED_UNICODE);
        $httpRequest = $this->requests->createRequest('POST', $this->baseUrl . '/chat')
            ->withHeader('Authorization', 'Bearer ' . $this->apiKey->getValue())
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream($body));

        try {
            $response = $this->retrier->send($this->client, $httpRequest)->response;
        } catch (TransportFailure $failure) {
            $message = $this->quote($failure->getMessage());

            // The cause names the client's exception type, but is not that exception.
            throw new ProviderException('Could not reach Acme Chat: ' . $message, 0, new ProviderException(get_debug_type($failure->getPrevious()) . ': ' . $message));
        }

        $data = json_decode((string) $response->getBody(), true);
        $data = \is_array($data) ? $data : null;
        $usage = TokenUsage::reported($this->tokens($data['usage']['in'] ?? null), $this->tokens($data['usage']['out'] ?? null));

        $status = $response->getStatusCode();
        if ($status < 200 || $status > 299) {
            $code = $data['error']['code'] ?? null;
            $message = $data['error']['message'] ?? null;

            throw ProviderException::fromHttpResponse(
                $status,
                \sprintf('Acme Chat returned HTTP %d%s.', $status, \is_string($message) ? ': ' . $this->quote($message) : ''),
                \is_string($code) ? $code : null,
                usage: $usage,
            );
        }

        $text = $data['text'] ?? null;
        if (!\is_string($text)) {
            throw new ProviderException(null === $data ? 'Acme Chat answered with a body that is not JSON.' : 'Acme Chat answered without text.', usage: $usage);
        }

        $model = $data['model'] ?? null;

        return new ChatResponse($text, \is_string($model) && '' !== trim($model) ? $model : $this->model, $usage?->inputTokens, $usage?->outputTokens, match ($data['stop'] ?? null) {
            null => null,
            'end' => FinishReason::Stop,
            'length' => FinishReason::Length,
            'filter' => FinishReason::ContentFilter,
            'refusal' => FinishReason::Refusal,
            default => FinishReason::Other,
        });
    }

    private function tokens(mixed $count): ?int
    {
        return \is_int($count) && $count >= 0 ? $count : null;
    }

    /**
     * Vendor and transport messages are untrusted and may repeat the key (e.g. in a URL).
     */
    private function quote(string $message): string
    {
        return UntrustedText::quote(str_replace($this->apiKey->getValue(), '[redacted]', $message));
    }
}
```

Its contract test describes the far side for each scenario (an answer, an HTTP error, a broken body, a transport and
a network error) and decodes the request the adapter sent, here with Symfony's mock client:

```php
use HtmlTranslator\Provider\Llm\Testing\ChatModelContractTestCase;
use HtmlTranslator\Provider\Llm\Testing\ChatScenario;
use HtmlTranslator\Provider\Llm\Testing\ChatScenarioKind;
use HtmlTranslator\Provider\Llm\Testing\FakeSleeper;
use HtmlTranslator\Provider\Llm\Testing\RecordedChatRequest;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AcmeChatModelContractTest extends ChatModelContractTestCase
{
    private string $lastBody = 'null';

    protected function modelFor(ChatScenario $scenario): ChatModelInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($scenario): MockResponse {
            $this->lastBody = $options['body'];

            return $this->answer($scenario);
        });
        $factory = new Psr17Factory();

        return new AcmeChatModel(new Psr18Client($http, $factory, $factory), $factory, $factory, 'acme-large', self::API_KEY, retrier: new Retrier(sleeper: new FakeSleeper()));
    }

    protected function recordedRequest(): RecordedChatRequest
    {
        $body = json_decode($this->lastBody, true);
        self::assertIsArray($body);

        return new RecordedChatRequest(
            array_map(static fn(array $message): array => [$message['role'], $message['content']], $body['messages']),
            $body['temperature'],
            $body['json'],
            $body['max_tokens'],
            $body['model'],
        );
    }

    private function answer(ChatScenario $scenario): MockResponse
    {
        return match ($scenario->kind) {
            ChatScenarioKind::Success => self::json(200, [
                'model' => $scenario->model,
                'text' => $scenario->content,
                'stop' => match ($scenario->finishReason) {
                    null => null,
                    FinishReason::Stop => 'end',
                    FinishReason::Length => 'length',
                    FinishReason::ContentFilter => 'filter',
                    FinishReason::Refusal => 'refusal',
                    FinishReason::Other => 'tool',
                },
                'usage' => ['in' => $scenario->inputTokens, 'out' => $scenario->outputTokens],
            ]),
            ChatScenarioKind::HttpError => $scenario->jsonErrorBody
                ? self::json($scenario->statusCode ?? 500, ['error' => ['code' => $scenario->vendorCode, 'message' => $scenario->errorMessage]])
                : new MockResponse('<html>' . $scenario->errorMessage . '</html>', ['http_code' => $scenario->statusCode ?? 502]),
            ChatScenarioKind::MalformedBody => new MockResponse('{"text": '),
            ChatScenarioKind::UnexpectedShape => self::json(200, ['answer' => 'no text field']),
            ChatScenarioKind::TransportError, ChatScenarioKind::NetworkError => new MockResponse('', ['error' => $scenario->errorMessage]),
        };
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function json(int $status, array $body): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }
}
```

The matches over `ChatScenarioKind` and `FinishReason` list every case: a minor version may add one (ADR-0018), and
the test then fails until it handles it, which is what a contract test should do. The adapter sends no answer
schema, so `RecordedChatRequest::$jsonSchema` stays null. An adapter for an API with
structured outputs sends `ChatRequest::$jsonSchema` and records it; the contract then checks that it arrives
unchanged.

## Token estimates

`HtmlTranslator` groups units into batches by an estimated token count (`TranslationOptions::$tokenBudget`). The
default `CharacterTokenEstimator` is a conservative character count; a vendor's tokenizer or counting endpoint can
replace it:

```php
use HtmlTranslator\Batching\TokenEstimatorInterface;

/**
 * About one token per word and per punctuation mark, as an example; a real one would use the model's tokenizer.
 */
final readonly class WordTokenEstimator implements TokenEstimatorInterface
{
    public function estimate(string $text): int
    {
        return \count(preg_split('/\s+|(?=\p{P})/u', $text, flags: \PREG_SPLIT_NO_EMPTY) ?: []);
    }
}

$translator = new HtmlTranslator(new PseudoLocalizationProvider(), tokenEstimator: new WordTokenEstimator());

echo new WordTokenEstimator()->estimate('Install the <g1>library</g1> first.');
```

```text
6
```

Estimate the unit text as given, pseudo-tags included: they are sent too.

## Waiting between retries

`Retrier` waits through `SleeperInterface` between attempts; the default `UsleepSleeper` blocks the process. An
application on an event loop passes a sleeper that suspends the current task instead, and a test passes
`Provider\Llm\Testing\FakeSleeper`, which records the waits without waiting:

```php
use HtmlTranslator\Provider\Llm\Bridge\RetryPolicy;
use HtmlTranslator\Provider\Llm\Bridge\SleeperInterface;

final readonly class CallbackSleeper implements SleeperInterface
{
    /**
     * @param \Closure(int): void $wait waits the given milliseconds without blocking the loop
     */
    public function __construct(
        private \Closure $wait,
    ) {}

    public function sleep(int $milliseconds): void
    {
        ($this->wait)($milliseconds);
    }
}

// With amphp/amp, for example: the current fiber is suspended, other tasks go on.
$retrier = new Retrier(new RetryPolicy(maxAttempts: 4), new CallbackSleeper(static fn(int $milliseconds) => \Amp\delay($milliseconds / 1000)));
```

Pass the retrier to the chat model adapter, e.g. `new OpenAiCompatibleChatModel($http, $http, $http, $config,
$retrier)`, or to your own adapter as above.

<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Provider\Llm\Bridge\OpenAiCompatible;

use HtmlTranslator\Exception\ProviderException;
use HtmlTranslator\Exception\UntrustedText;
use HtmlTranslator\Provider\Llm\Bridge\HttpSecrets;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\JsonMode;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\OpenAiCompatibleChatModel;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\OpenAiCompatibleConfig;
use HtmlTranslator\Provider\Llm\Bridge\RetriedResponse;
use HtmlTranslator\Provider\Llm\Bridge\Retrier;
use HtmlTranslator\Provider\Llm\Bridge\RetryPolicy;
use HtmlTranslator\Provider\Llm\Bridge\TransportFailure;
use HtmlTranslator\Provider\Llm\ChatMessage;
use HtmlTranslator\Provider\Llm\ChatRequest;
use HtmlTranslator\Provider\Llm\ChatResponse;
use HtmlTranslator\Provider\Llm\ChatRole;
use HtmlTranslator\Provider\Llm\FinishReason;
use HtmlTranslator\Provider\Llm\JsonSchema;
use HtmlTranslator\Provider\Llm\Testing\FakeSleeper;
use HtmlTranslator\Tests\Support\FakeClientException;
use HtmlTranslator\Tests\Support\FakeHttpClient;
use HtmlTranslator\Tests\Support\FakeNetworkException;
use HtmlTranslator\Tests\Support\FakeRequestException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

/**
 * Adapter-specific behaviour against vendor-shaped bodies in Fixtures/ (see its README); the shared behaviour is
 * covered by OpenAiCompatibleChatModelContractTest.
 */
#[CoversClass(OpenAiCompatibleChatModel::class)]
#[UsesClass(OpenAiCompatibleConfig::class)]
#[UsesClass(ChatRequest::class)]
#[UsesClass(ChatMessage::class)]
#[UsesClass(ChatResponse::class)]
#[UsesClass(ProviderException::class)]
#[UsesClass(JsonSchema::class)]
#[UsesClass(UntrustedText::class)]
#[UsesClass(HttpSecrets::class)]
#[UsesClass(Retrier::class)]
#[UsesClass(RetryPolicy::class)]
#[UsesClass(RetriedResponse::class)]
#[UsesClass(TransportFailure::class)]
#[UsesClass(FakeSleeper::class)]
final class OpenAiCompatibleChatModelTest extends TestCase
{
    private const string API_KEY = 'sk-test-SECRET-0123456789';

    private const string FIXTURES = __DIR__ . '/Fixtures';

    /** Compact, as JsonSchema keeps it; the empty object checks that {} is not sent as []. */
    private const string SCHEMA = '{"type":"object","properties":{"units":{"type":"array","items":{"type":"object","properties":{},"required":[],"additionalProperties":false}}},"required":["units"],"additionalProperties":false}';

    /**
     * @return iterable<string, array{string, string, string, ?int, ?int, ?FinishReason}> fixture, content, model, tokens, finish reason
     */
    public static function answers(): iterable
    {
        yield 'OpenAI success' => ['success.json', '{"units": [{"id": "u1", "text": "Cześć <g1>świecie</g1>"}]}', 'gpt-4o-mini-2024-07-18', 412, 23, FinishReason::Stop];
        yield 'code-fenced JSON is passed on as it is' => ['success-code-fence.json', "```json\n{\"units\": [{\"id\": \"u1\", \"text\": \"Cześć\"}]}\n```", 'llama3.1:8b', 380, 31, FinishReason::Stop];
        yield 'cut off' => ['finish-length.json', '{"units": [{"id": "u1", "text": "Cze', 'gpt-4o-mini-2024-07-18', 412, 16, FinishReason::Length];
        yield 'content filter without content' => ['finish-content-filter.json', '', 'gpt-4o-mini', 412, 0, FinishReason::ContentFilter];
        yield 'refusal with finish reason stop' => ['refusal.json', "I'm sorry, I can't assist with that request.", 'gpt-4o-2024-08-06', 412, 12, FinishReason::Refusal];
        yield 'content filter without a message' => ['finish-content-filter-without-message.json', '', 'gemini-2.5-flash', null, null, FinishReason::ContentFilter];
        yield 'no model, usage or finish reason' => ['no-model-no-usage.json', '{"units": [{"id": "u1", "text": "Cześć"}]}', 'gpt-4o-mini', null, null, null];
    }

    #[Test]
    #[DataProvider('answers')]
    public function it_maps_vendor_answers(string $fixture, string $content, string $model, ?int $inputTokens, ?int $outputTokens, ?FinishReason $finishReason): void
    {
        $response = $this->model($this->client(200, $fixture))->complete($this->request());

        self::assertSame($content, $response->content);
        self::assertSame($model, $response->model);
        self::assertSame($inputTokens, $response->inputTokens);
        self::assertSame($outputTokens, $response->outputTokens);
        self::assertSame($finishReason, $response->finishReason);
    }

    /**
     * @return iterable<string, array{int, string, ?string, string}> status, fixture, vendor code, message
     */
    public static function errors(): iterable
    {
        yield 'unsupported parameter' => [400, 'error-400.json', 'invalid_request_error', "openai returned HTTP 400: Invalid parameter: 'response_format' of type 'json_object' is not supported with this model."];
        yield 'wrong key' => [401, 'error-401.json', 'invalid_api_key', 'openai returned HTTP 401: Incorrect API key provided: [redacted]. You can find your API key at https://platform.openai.com/account/api-keys.'];
        yield 'rate limit' => [429, 'error-429.json', 'rate_limit_exceeded', 'openai returned HTTP 429: Rate limit reached for gpt-4o-mini in organization org-abc123 on requests per min (RPM): Limit 500, Used 500, Requested 1. Please try again in 120ms.'];
        yield 'server error' => [500, 'error-500.json', 'server_error', 'openai returned HTTP 500: The server had an error while processing your request. Sorry about that!'];
        yield 'Gemini error list' => [400, 'error-gemini-400.json', 'INVALID_ARGUMENT', 'gemini returned HTTP 400: Invalid JSON payload received. Unknown name "max_completion_tokenz": Cannot find field.'];
        yield 'proxy page' => [502, 'error-502.html', null, 'openai returned HTTP 502.'];
    }

    #[Test]
    #[DataProvider('errors')]
    public function it_reports_vendor_errors(int $status, string $fixture, ?string $vendorCode, string $message): void
    {
        $config = str_contains($fixture, 'gemini') ? OpenAiCompatibleConfig::gemini(self::API_KEY, 'gemini-2.5-flash') : null;
        $exception = $this->failure($this->model($this->client($status, $fixture), $config));

        self::assertSame($status, $exception->statusCode);
        self::assertSame($vendorCode, $exception->vendorCode);
        self::assertSame($message, $exception->getMessage());
    }

    /**
     * @return iterable<string, array{array<mixed>, string, ?string}> body, message, vendor code
     */
    public static function errorBodies(): iterable
    {
        yield 'error without message or code' => [['error' => ['message' => null, 'code' => 42]], 'openai returned HTTP 503.', null];
        yield 'blank code, then type' => [['error' => ['message' => 'Busy.', 'code' => '', 'type' => 'overloaded']], 'openai returned HTTP 503: Busy.', 'overloaded'];
        yield 'no error object' => [['detail' => 'Busy'], 'openai returned HTTP 503.', null];
        yield 'list without error object' => [['Busy'], 'openai returned HTTP 503.', null];
        yield 'exactly 200 characters' => [['error' => ['message' => str_repeat('a', 200)]], 'openai returned HTTP 503: ' . str_repeat('a', 200), null];
        yield '201 characters' => [['error' => ['message' => 'b' . str_repeat('a', 200)]], 'openai returned HTTP 503: b' . str_repeat('a', 198) . '…', null];
        yield '200 characters of two bytes each' => [['error' => ['message' => str_repeat('ż', 200)]], 'openai returned HTTP 503: ' . str_repeat('ż', 200), null];
        yield '201 characters of two bytes each' => [['error' => ['message' => str_repeat('ż', 201)]], 'openai returned HTTP 503: ' . str_repeat('ż', 199) . '…', null];
    }

    /**
     * @param array<mixed> $body
     */
    #[Test]
    #[DataProvider('errorBodies')]
    public function it_builds_error_messages_from_what_the_vendor_sent(array $body, string $message, ?string $vendorCode): void
    {
        $json = json_encode($body, \JSON_THROW_ON_ERROR);
        $exception = $this->failure($this->model(new FakeHttpClient(static fn(): Response => new Response(503, [], $json))));

        self::assertSame($message, $exception->getMessage());
        self::assertSame($vendorCode, $exception->vendorCode);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function successStatuses(): iterable
    {
        yield 'created' => [201];
        yield 'last of 2xx' => [299];
    }

    #[Test]
    #[DataProvider('successStatuses')]
    public function it_accepts_every_2xx_status(int $status): void
    {
        self::assertSame(FinishReason::Stop, $this->model($this->client($status, 'success.json'))->complete($this->request())->finishReason);
    }

    #[Test]
    public function it_treats_a_300_status_as_an_error(): void
    {
        self::assertSame(300, $this->failure($this->model($this->client(300, 'success.json')))->statusCode);
    }

    /**
     * @return iterable<string, array{string, ?FinishReason}>
     */
    public static function otherFinishReasons(): iterable
    {
        yield 'function call' => ['"function_call"', FinishReason::Other];
        yield 'unknown' => ['"eos_token"', FinishReason::Other];
        yield 'not a string' => ['1', FinishReason::Other];
        yield 'null' => ['null', null];
    }

    #[Test]
    #[DataProvider('otherFinishReasons')]
    public function it_maps_other_finish_reasons(string $json, ?FinishReason $reason): void
    {
        $body = '{"choices": [{"message": {"content": "ok"}, "finish_reason": ' . $json . '}]}';

        self::assertSame($reason, $this->model(new FakeHttpClient(static fn(): Response => new Response(200, [], $body)))->complete($this->request())->finishReason);
    }

    #[Test]
    public function it_tells_a_missing_choice_from_a_missing_message(): void
    {
        $noChoice = $this->failure($this->model(new FakeHttpClient(static fn(): Response => new Response(200, [], '{"choices": []}'))));
        $noMessage = $this->failure($this->model(new FakeHttpClient(static fn(): Response => new Response(200, [], '{"choices": [{}]}'))));

        self::assertSame('openai answered without a choice.', $noChoice->getMessage());
        self::assertSame('openai answered without a message.', $noMessage->getMessage());
    }

    #[Test]
    public function it_ignores_an_empty_refusal(): void
    {
        $body = '{"choices": [{"message": {"content": "ok", "refusal": ""}, "finish_reason": "stop"}]}';

        $response = $this->model(new FakeHttpClient(static fn(): Response => new Response(200, [], $body)))->complete($this->request());

        self::assertSame('ok', $response->content);
        self::assertSame(FinishReason::Stop, $response->finishReason);
    }

    #[Test]
    public function it_ignores_a_blank_model_and_invalid_token_counts(): void
    {
        $body = '{"model": " ", "choices": [{"message": {"content": "ok"}, "finish_reason": "tool_calls"}], "usage": {"prompt_tokens": -1, "completion_tokens": "12"}}';

        $response = $this->model(new FakeHttpClient(static fn(): Response => new Response(200, [], $body)))->complete($this->request());

        self::assertSame('gpt-4o-mini', $response->model);
        self::assertNull($response->inputTokens);
        self::assertNull($response->outputTokens);
        self::assertSame(FinishReason::Other, $response->finishReason);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableBodies(): iterable
    {
        yield 'not JSON' => ['<html>Service Unavailable</html>'];
        yield 'no choices' => ['{"object": "chat.completion", "choices": []}'];
        yield 'choice without message or finish reason' => ['{"choices": [{"index": 0}]}'];
        yield 'choice that is not an object' => ['{"choices": ["Hi"]}'];
        yield 'content of another type' => ['{"choices": [{"message": {"role": "assistant", "content": [{"type": "text", "text": "Hi"}]}}]}'];
        yield 'JSON list' => ['[1, 2]'];
    }

    #[Test]
    #[DataProvider('unusableBodies')]
    public function it_rejects_a_successful_response_it_cannot_read(string $body): void
    {
        $exception = $this->failure($this->model(new FakeHttpClient(static fn(): Response => new Response(200, [], $body))));

        self::assertNull($exception->statusCode);
        self::assertSame(0, $exception->getCode());
        self::assertStringStartsWith('openai answered ', $exception->getMessage());
    }

    #[Test]
    public function it_sends_the_request_to_the_configured_endpoint(): void
    {
        $client = $this->client(200, 'success.json');
        $config = new OpenAiCompatibleConfig('openai', 'https://api.openai.com/v1', 'gpt-4o-mini', self::API_KEY, maxTokensField: 'max_completion_tokens', extraHeaders: ['OpenAI-Organization' => 'org-123']);

        $this->model($client, $config)->complete(new ChatRequest(
            [new ChatMessage(ChatRole::System, 'Answer in JSON.'), new ChatMessage(ChatRole::User, 'Zażółć <g1>gęślą</g1> / jaźń')],
            temperature: 0.0,
            jsonResponse: true,
            maxOutputTokens: 512,
        ));

        $sent = $client->requests[0] ?? self::fail('Nothing was sent.');
        self::assertSame('POST', $sent->getMethod());
        self::assertSame('https://api.openai.com/v1/chat/completions', (string) $sent->getUri());
        self::assertSame('Bearer ' . self::API_KEY, $sent->getHeaderLine('Authorization'));
        self::assertSame('application/json', $sent->getHeaderLine('Content-Type'));
        self::assertSame('org-123', $sent->getHeaderLine('OpenAI-Organization'));
        self::assertStringContainsString('"temperature":0.0', (string) $sent->getBody());
        self::assertSame([
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => 'Answer in JSON.'],
                ['role' => 'user', 'content' => 'Zażółć <g1>gęślą</g1> / jaźń'],
            ],
            'temperature' => 0.0,
            'max_completion_tokens' => 512,
            'response_format' => ['type' => 'json_object'],
        ], $this->body($sent));
    }

    /**
     * @return iterable<string, array{JsonMode, bool, ?array<string, mixed>}> mode, whether the request has a schema, expected response_format
     */
    public static function responseFormats(): iterable
    {
        $withSchema = ['type' => 'json_schema', 'json_schema' => ['name' => 'answer', 'strict' => true, 'schema' => json_decode(self::SCHEMA, true, 16, \JSON_THROW_ON_ERROR)]];

        yield 'JSON object' => [JsonMode::JsonObject, false, ['type' => 'json_object']];
        yield 'JSON object ignores a schema' => [JsonMode::JsonObject, true, ['type' => 'json_object']];
        yield 'schema' => [JsonMode::JsonSchema, true, $withSchema];
        yield 'schema mode falls back to JSON object' => [JsonMode::JsonSchema, false, ['type' => 'json_object']];
        yield 'schema only' => [JsonMode::JsonSchemaOnly, true, $withSchema];
        yield 'schema only sends nothing without a schema' => [JsonMode::JsonSchemaOnly, false, null];
        yield 'none' => [JsonMode::None, true, null];
        yield 'none without a schema' => [JsonMode::None, false, null];
    }

    /**
     * @param array<string, mixed>|null $expected
     */
    #[Test]
    #[DataProvider('responseFormats')]
    public function it_asks_for_json_the_way_the_server_supports(JsonMode $mode, bool $withSchema, ?array $expected): void
    {
        $client = $this->client(200, 'success.json');
        $config = new OpenAiCompatibleConfig('local', 'http://localhost:1234/v1', 'qwen2.5-7b-instruct', jsonMode: $mode);

        $this->model($client, $config)->complete(new ChatRequest([new ChatMessage(ChatRole::User, 'Answer in JSON.')], jsonResponse: true, jsonSchema: $withSchema ? JsonSchema::fromJson(self::SCHEMA) : null));

        self::assertSame($expected, $this->body($client->requests[0] ?? self::fail('Nothing was sent.'))['response_format'] ?? null);
    }

    #[Test]
    public function it_sends_the_schema_as_given(): void
    {
        $client = $this->client(200, 'success.json');

        $this->model($client, OpenAiCompatibleConfig::openAi(self::API_KEY, 'gpt-4o-mini'))->complete(new ChatRequest([new ChatMessage(ChatRole::User, 'Answer in JSON.')], jsonResponse: true, jsonSchema: JsonSchema::fromJson(self::SCHEMA)));

        // An empty object must stay {} on the wire: OpenAI rejects "properties": [].
        self::assertStringContainsString('"json_schema":{"name":"answer","strict":true,"schema":' . self::SCHEMA . '}', (string) ($client->requests[0] ?? self::fail('Nothing was sent.'))->getBody());
    }

    /**
     * @return iterable<string, array{JsonMode}>
     */
    public static function jsonModes(): iterable
    {
        foreach (JsonMode::cases() as $mode) {
            yield $mode->name => [$mode];
        }
    }

    #[Test]
    #[DataProvider('jsonModes')]
    public function it_asks_for_no_json_when_the_request_does_not(JsonMode $mode): void
    {
        $client = $this->client(200, 'success.json');

        $this->model($client, new OpenAiCompatibleConfig('local', 'http://localhost:1234/v1', 'qwen2.5-7b-instruct', jsonMode: $mode))->complete(new ChatRequest([new ChatMessage(ChatRole::User, 'Hi')]));

        self::assertArrayNotHasKey('response_format', $this->body($client->requests[0] ?? self::fail('Nothing was sent.')));
    }

    #[Test]
    public function it_sends_no_key_to_a_server_without_authentication(): void
    {
        $client = $this->client(200, 'success.json');

        $this->model($client, OpenAiCompatibleConfig::lmStudio('qwen2.5-7b-instruct'))->complete(new ChatRequest([new ChatMessage(ChatRole::User, 'Hi')]));

        self::assertFalse(($client->requests[0] ?? self::fail('Nothing was sent.'))->hasHeader('Authorization'));
    }

    #[Test]
    public function it_sends_text_with_broken_encoding_instead_of_failing(): void
    {
        $client = $this->client(200, 'success.json');

        $this->model($client)->complete(new ChatRequest([new ChatMessage(ChatRole::User, "Cze\xC5")]));

        self::assertSame([['role' => 'user', 'content' => "Cze\u{FFFD}"]], $this->body($client->requests[0] ?? self::fail('Nothing was sent.'))['messages'] ?? null);
    }

    #[Test]
    public function it_never_keeps_the_client_exception_whose_trace_recorded_the_request(): void
    {
        $cause = new FakeClientException('Connection refused');
        $exception = $this->failure($this->model(new FakeHttpClient(static fn(): never => throw $cause)));

        self::assertNotSame($cause, $exception->getPrevious());
        self::assertInstanceOf(ProviderException::class, $exception->getPrevious());
        self::assertSame(FakeClientException::class . ': Connection refused', $exception->getPrevious()->getMessage());
        self::assertSame('Could not reach openai at https://api.openai.com/v1/chat/completions: Connection refused', $exception->getMessage());
        self::assertSame(0, $exception->getCode());
    }

    #[Test]
    public function it_replaces_a_transport_error_that_contains_the_key(): void
    {
        $exception = $this->failure($this->model(new FakeHttpClient(static fn(): never => throw new FakeClientException('Failed for ' . self::API_KEY))));

        for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
            self::assertStringNotContainsString(self::API_KEY, $current->getMessage());
        }

        self::assertSame(FakeClientException::class . ': Failed for [redacted]', $exception->getPrevious()?->getMessage());
        self::assertSame(0, $exception->getPrevious()->getCode());
    }

    #[Test]
    public function it_replaces_a_transport_error_whose_cause_contains_the_key(): void
    {
        $error = new FakeClientException('cURL error 28: timed out', 0, new RuntimeException('Request to https://api.example.test/?key=' . self::API_KEY));

        $exception = $this->failure($this->model(new FakeHttpClient(static fn(): never => throw $error)));

        for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
            self::assertStringNotContainsString(self::API_KEY, $current->getMessage());
        }

        self::assertNotSame($error, $exception->getPrevious());
        self::assertSame(FakeClientException::class . ': cURL error 28: timed out', $exception->getPrevious()?->getMessage());
    }

    #[Test]
    public function it_retries_a_rate_limit_and_says_how_often_it_tried(): void
    {
        $sleeper = new FakeSleeper();

        $exception = $this->failure($this->model($this->client(429, 'error-429.json'), retrier: new Retrier(sleeper: $sleeper)));

        self::assertSame(429, $exception->statusCode);
        self::assertSame('rate_limit_exceeded', $exception->vendorCode);
        self::assertStringStartsWith('openai returned HTTP 429 after 3 attempts: Rate limit reached', $exception->getMessage());
        self::assertCount(2, $sleeper->delays);
    }

    #[Test]
    public function it_says_how_often_it_tried_to_reach_the_server(): void
    {
        $model = $this->model(new FakeHttpClient(static fn(RequestInterface $request): Response => throw new FakeNetworkException('Connection reset', $request)), retrier: new Retrier(sleeper: new FakeSleeper()));

        self::assertStringStartsWith('Could not reach openai at https://api.openai.com/v1/chat/completions after 3 attempts: Connection reset', $this->failure($model)->getMessage());
    }

    #[Test]
    public function it_names_the_configured_model(): void
    {
        self::assertSame('openai:gpt-4o-mini', $this->model($this->client(200, 'success.json'))->modelId());
    }

    #[Test]
    public function it_replaces_a_network_exception_that_holds_the_request(): void
    {
        $model = $this->model(new FakeHttpClient(static fn(RequestInterface $request): Response => throw new FakeNetworkException('Operation timed out', $request)));

        $exception = $this->failure($model);

        self::assertStringContainsString('Operation timed out', $exception->getMessage());
        self::assertSame(FakeNetworkException::class . ': Operation timed out', $exception->getPrevious()?->getMessage());
        $this->assertNoRequestInChain($exception);
    }

    #[Test]
    public function it_replaces_a_client_exception_wrapping_a_request_exception(): void
    {
        $model = $this->model(new FakeHttpClient(static fn(RequestInterface $request): Response => throw new FakeClientException('Transport failed', 0, new FakeRequestException('Invalid header', $request))));

        $exception = $this->failure($model);

        self::assertSame(FakeClientException::class . ': Transport failed', $exception->getPrevious()?->getMessage());
        $this->assertNoRequestInChain($exception);
    }

    private function assertNoRequestInChain(ProviderException $exception): void
    {
        self::assertNotNull($exception->getPrevious(), 'The transport error must be kept in a safe form.');
        for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $current);
            self::assertNotInstanceOf(RequestExceptionInterface::class, $current);
            self::assertNotInstanceOf(FakeClientException::class, $current);
        }
    }

    /**
     * Without retries, so every scripted response is the one the adapter reports; retries have their own tests.
     */
    private function model(FakeHttpClient $client, ?OpenAiCompatibleConfig $config = null, ?Retrier $retrier = null): OpenAiCompatibleChatModel
    {
        $factory = new Psr17Factory();

        return new OpenAiCompatibleChatModel($client, $factory, $factory, $config ?? OpenAiCompatibleConfig::openAi(self::API_KEY, 'gpt-4o-mini'), $retrier ?? new Retrier(RetryPolicy::none()));
    }

    private function client(int $status, string $fixture): FakeHttpClient
    {
        $body = file_get_contents(self::FIXTURES . '/' . $fixture);
        self::assertIsString($body);
        $type = str_ends_with($fixture, '.json') ? 'application/json' : 'text/html';

        return new FakeHttpClient(static fn(): Response => new Response($status, ['Content-Type' => $type], $body));
    }

    private function failure(OpenAiCompatibleChatModel $model): ProviderException
    {
        try {
            $model->complete($this->request());
        } catch (ProviderException $exception) {
            return $exception;
        }

        self::fail('Expected a ProviderException.');
    }

    private function request(): ChatRequest
    {
        return new ChatRequest([new ChatMessage(ChatRole::User, 'Hello')]);
    }

    /**
     * @return array<mixed>
     */
    private function body(RequestInterface $request): array
    {
        $body = json_decode((string) $request->getBody(), true, 16, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }
}

<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Provider\Llm\Bridge\Anthropic;

use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Exception\ProviderException;
use HtmlTranslator\Exception\UntrustedText;
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicChatModel;
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicConfig;
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicEffort;
use HtmlTranslator\Provider\Llm\ChatMessage;
use HtmlTranslator\Provider\Llm\ChatRequest;
use HtmlTranslator\Provider\Llm\ChatResponse;
use HtmlTranslator\Provider\Llm\ChatRole;
use HtmlTranslator\Provider\Llm\FinishReason;
use HtmlTranslator\Provider\Llm\JsonSchema;
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
use Throwable;

/**
 * Adapter-specific behaviour against vendor-shaped bodies in Fixtures/ (see its README); the shared behaviour is
 * covered by AnthropicChatModelContractTest.
 */
#[CoversClass(AnthropicChatModel::class)]
#[UsesClass(AnthropicConfig::class)]
#[UsesClass(ChatRequest::class)]
#[UsesClass(ChatMessage::class)]
#[UsesClass(ChatResponse::class)]
#[UsesClass(JsonSchema::class)]
#[UsesClass(ProviderException::class)]
#[UsesClass(InvalidArgumentException::class)]
#[UsesClass(UntrustedText::class)]
final class AnthropicChatModelTest extends TestCase
{
    private const string API_KEY = 'sk-ant-test-SECRET-0123456789';

    private const string FIXTURES = __DIR__ . '/Fixtures';

    private const string SCHEMA = '{"type":"object","properties":{"units":{"type":"array"}},"required":["units"],"additionalProperties":false}';

    /**
     * @return iterable<string, array{string, string, string, ?int, ?int, ?FinishReason}> fixture, content, model, tokens, finish reason
     */
    public static function answers(): iterable
    {
        yield 'success' => ['success.json', '{"units": [{"id": "u1", "text": "Cześć <g1>świecie</g1>"}]}', 'claude-opus-5-5', 512, 31, FinishReason::Stop];
        yield 'thinking block skipped, text blocks joined' => ['success-with-thinking.json', '{"units": [{"id": "u1", "text": "Cześć"}]}', 'claude-opus-5-5', 530, 210, FinishReason::Stop];
        yield 'refusal without content' => ['refusal.json', '', 'claude-opus-5-5', 512, 0, FinishReason::Refusal];
        yield 'cut off at max_tokens' => ['max-tokens.json', '{"units": [{"id": "u1", "text": "Cze', 'claude-opus-5-5', 512, 16000, FinishReason::Length];
        yield 'context window exceeded' => ['context-window-exceeded.json', '', 'claude-sonnet-5-5', 999000, 0, FinishReason::Length];
        yield 'answered by a fallback model' => ['fallback-model.json', '{"units": [{"id": "u1", "text": "Cześć"}]}', 'claude-opus-4-8', 512, 24, FinishReason::Stop];
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
     * @return iterable<string, array{string, ?FinishReason}>
     */
    public static function stopReasons(): iterable
    {
        yield 'stop sequence' => ['stop_sequence', FinishReason::Stop];
        yield 'tool use' => ['tool_use', FinishReason::Other];
        yield 'pause turn' => ['pause_turn', FinishReason::Other];
        yield 'unknown' => ['something_new', FinishReason::Other];
        yield 'missing' => ['', null];
    }

    #[Test]
    #[DataProvider('stopReasons')]
    public function it_maps_other_stop_reasons(string $stopReason, ?FinishReason $expected): void
    {
        $body = ['type' => 'message', 'model' => 'claude-opus-5-5', 'content' => [['type' => 'text', 'text' => 'ok']]];
        if ('' !== $stopReason) {
            $body['stop_reason'] = $stopReason;
        }

        $response = $this->model($this->jsonClient(200, $body))->complete($this->request());

        self::assertSame($expected, $response->finishReason);
    }

    /**
     * @return iterable<string, array{int, string, ?string, string}> status, fixture, vendor code, message
     */
    public static function errors(): iterable
    {
        yield 'bad request' => [400, 'error-400.json', 'invalid_request_error', 'anthropic returned HTTP 400 (request req_011CSHoEeqs5C35K2UUqR7Fy): max_tokens: Field required'];
        yield 'wrong key, echoed' => [401, 'error-401.json', 'authentication_error', 'anthropic returned HTTP 401 (request req_011CSHoEeqs5C35K2UUqR7Fy): invalid x-api-key [redacted]'];
        yield 'rate limit' => [429, 'error-429.json', 'rate_limit_error', 'anthropic returned HTTP 429 (request req_011CSHoEeqs5C35K2UUqR7Fy): Number of request tokens has exceeded your per-minute rate limit.'];
        yield 'server error' => [500, 'error-500.json', 'api_error', 'anthropic returned HTTP 500 (request req_011CSHoEeqs5C35K2UUqR7Fy): Internal server error'];
        yield 'overloaded' => [529, 'error-529.json', 'overloaded_error', 'anthropic returned HTTP 529 (request req_011CSHoEeqs5C35K2UUqR7Fy): Overloaded'];
    }

    #[Test]
    #[DataProvider('errors')]
    public function it_reports_vendor_errors(int $status, string $fixture, ?string $vendorCode, string $message): void
    {
        $exception = $this->failure($this->model($this->client($status, $fixture)));

        self::assertSame($status, $exception->statusCode);
        self::assertSame($vendorCode, $exception->vendorCode);
        self::assertSame($message, $exception->getMessage());
    }

    #[Test]
    public function it_cuts_long_vendor_messages(): void
    {
        $exception = $this->failure($this->model($this->jsonClient(400, ['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'b' . str_repeat('ż', 200)]])));

        self::assertSame('anthropic returned HTTP 400: b' . str_repeat('ż', 198) . '…', $exception->getMessage());
    }

    #[Test]
    public function it_takes_the_request_id_from_the_header_when_the_body_has_none(): void
    {
        $exception = $this->failure($this->model(new FakeHttpClient(static fn(): Response => new Response(529, ['request-id' => 'req_from_header'], '{"type": "error", "error": {"type": "overloaded_error", "message": "Overloaded"}}'))));

        self::assertSame('anthropic returned HTTP 529 (request req_from_header): Overloaded', $exception->getMessage());
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function unquotedRequestIds(): iterable
    {
        yield 'markup in the header' => [['request-id' => 'req 1; <script>'], '"Service unavailable"'];
        yield 'junk before an id' => [['request-id' => 'x;req_1'], '"Service unavailable"'];
        yield 'line break after an id in the body' => [[], '{"type": "error", "request_id": "req_1\\n"}'];
    }

    /**
     * @param array<string, string> $headers
     */
    #[Test]
    #[DataProvider('unquotedRequestIds')]
    public function it_quotes_only_a_plain_request_id(array $headers, string $body): void
    {
        $exception = $this->failure($this->model(new FakeHttpClient(static fn(): Response => new Response(503, $headers, $body))));

        self::assertSame('anthropic returned HTTP 503.', $exception->getMessage());
        self::assertNull($exception->vendorCode);
    }

    #[Test]
    public function it_keeps_vendor_messages_of_exactly_200_characters(): void
    {
        $exception = $this->failure($this->model($this->jsonClient(400, ['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => str_repeat('ż', 200)]])));

        self::assertSame('anthropic returned HTTP 400: ' . str_repeat('ż', 200), $exception->getMessage());
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function statuses(): iterable
    {
        yield '200' => [200, true];
        yield '299' => [299, true];
        yield '300' => [300, false];
        yield '199' => [199, false];
    }

    #[Test]
    #[DataProvider('statuses')]
    public function it_accepts_only_2xx_statuses(int $status, bool $accepted): void
    {
        $model = $this->model($this->client($status, 'success.json'));

        if (!$accepted) {
            $this->expectException(ProviderException::class);
        }

        self::assertSame('claude-opus-5-5', $model->complete($this->request())->model);
    }

    #[Test]
    public function it_ignores_a_blank_model_and_invalid_token_counts(): void
    {
        $response = $this->model($this->jsonClient(200, ['type' => 'message', 'model' => '  ', 'content' => [], 'usage' => ['input_tokens' => -1, 'output_tokens' => '12']]))->complete($this->request());

        self::assertSame('claude-opus-5-5', $response->model);
        self::assertNull($response->inputTokens);
        self::assertNull($response->outputTokens);
    }

    #[Test]
    public function it_reports_an_error_without_a_readable_body(): void
    {
        $exception = $this->failure($this->model(new FakeHttpClient(static fn(): Response => new Response(502, [], '<html>Bad gateway</html>'))));

        self::assertSame(502, $exception->statusCode);
        self::assertNull($exception->vendorCode);
        self::assertSame('anthropic returned HTTP 502.', $exception->getMessage());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unreadableAnswers(): iterable
    {
        yield 'not JSON' => ['{"type": "message", "content": [', 'not valid JSON'];
        yield 'a JSON list' => ['[1, 2]', 'not an object'];
        yield 'a JSON string' => ['"message"', 'not an object'];
        yield 'no content' => ['{"type": "message", "model": "claude-opus-5-5"}', 'without a content list'];
        yield 'content that is not a list' => ['{"type": "message", "content": {"type": "text"}}', 'without a content list'];
        yield 'text block without text' => ['{"type": "message", "content": [{"type": "text"}]}', 'without text'];
    }

    #[Test]
    #[DataProvider('unreadableAnswers')]
    public function it_rejects_a_successful_response_it_cannot_read(string $body, string $messagePart): void
    {
        $exception = $this->failure($this->model(new FakeHttpClient(static fn(): Response => new Response(200, [], $body))));

        self::assertStringContainsString($messagePart, $exception->getMessage());
        self::assertSame(0, $exception->getCode());
        self::assertNull($exception->statusCode);
    }

    #[Test]
    public function it_maps_the_request_to_the_messages_api(): void
    {
        $client = $this->client(200, 'success.json');

        $this->model($client)->complete(new ChatRequest(
            [new ChatMessage(ChatRole::System, 'Translate into Polish.'), new ChatMessage(ChatRole::User, '{"units": []}')],
            temperature: 0.0,
            jsonResponse: true,
            maxOutputTokens: 4096,
            jsonSchema: JsonSchema::fromJson(self::SCHEMA),
        ));

        $request = $client->requests[0] ?? self::fail('No request was sent.');
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.anthropic.com/v1/messages', (string) $request->getUri());
        self::assertSame(self::API_KEY, $request->getHeaderLine('x-api-key'));
        self::assertSame('2023-06-01', $request->getHeaderLine('anthropic-version'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertFalse($request->hasHeader('anthropic-beta'));
        self::assertSame(
            '{"model":"claude-opus-5-5","max_tokens":4096,"system":"Translate into Polish.","messages":[{"role":"user","content":"{\"units\": []}"}],"temperature":0.0,"output_config":{"format":{"type":"json_schema","schema":' . self::SCHEMA . '}}}',
            (string) $request->getBody(),
        );
    }

    #[Test]
    public function it_sends_its_default_output_limit_and_no_optional_fields(): void
    {
        $client = $this->client(200, 'success.json');

        $this->model($client)->complete($this->request());

        self::assertSame(
            ['model' => 'claude-opus-5-5', 'max_tokens' => 16000, 'messages' => [['role' => 'user', 'content' => 'Hello']]],
            $this->body($client),
        );
    }

    #[Test]
    public function it_relies_on_the_prompt_when_json_is_asked_for_without_a_schema(): void
    {
        $client = $this->client(200, 'success.json');

        $this->model($client)->complete(new ChatRequest([new ChatMessage(ChatRole::User, 'Answer in JSON.')], jsonResponse: true));

        self::assertArrayNotHasKey('output_config', $this->body($client));
    }

    #[Test]
    public function it_sends_effort_and_refusal_fallbacks_when_configured(): void
    {
        $client = $this->client(200, 'success.json');
        $config = new AnthropicConfig('claude-opus-5-5', self::API_KEY, effort: AnthropicEffort::Low, refusalFallbacks: true);

        new AnthropicChatModel($client, new Psr17Factory(), new Psr17Factory(), $config)->complete(new ChatRequest(
            [new ChatMessage(ChatRole::User, 'Answer in JSON.')],
            jsonResponse: true,
            jsonSchema: JsonSchema::fromJson(self::SCHEMA),
        ));

        $body = $this->body($client);
        self::assertSame(['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => json_decode(self::SCHEMA, true, 16, \JSON_THROW_ON_ERROR)]], $body['output_config'] ?? null);
        self::assertSame('default', $body['fallbacks'] ?? null);
        self::assertSame('server-side-fallback-2026-07-01', ($client->requests[0] ?? self::fail('No request.'))->getHeaderLine('anthropic-beta'));
    }

    #[Test]
    public function it_keeps_empty_objects_of_the_schema_as_objects(): void
    {
        $client = $this->client(200, 'success.json');

        $this->model($client)->complete(new ChatRequest(
            [new ChatMessage(ChatRole::User, 'Answer in JSON.')],
            jsonResponse: true,
            jsonSchema: JsonSchema::fromJson('{"type": "object", "properties": {}, "additionalProperties": false}'),
        ));

        $request = $client->requests[0] ?? self::fail('No request was sent.');
        self::assertStringContainsString('"output_config":{"format":{"type":"json_schema","schema":{"type":"object","properties":{},"additionalProperties":false}}}', (string) $request->getBody());
    }

    #[Test]
    public function it_rejects_a_prefilled_answer_without_calling_the_api(): void
    {
        $client = $this->client(200, 'success.json');

        try {
            $this->model($client)->complete(new ChatRequest([new ChatMessage(ChatRole::User, 'Answer in JSON.'), new ChatMessage(ChatRole::Assistant, '{')]));
            self::fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('prefill', $exception->getMessage());
        }

        self::assertSame([], $client->requests);
    }

    #[Test]
    public function it_sends_an_earlier_assistant_message_of_the_conversation(): void
    {
        $client = $this->client(200, 'success.json');

        $this->model($client)->complete(new ChatRequest([
            new ChatMessage(ChatRole::User, 'Hi'),
            new ChatMessage(ChatRole::Assistant, 'Hello'),
            new ChatMessage(ChatRole::User, 'Translate.'),
        ]));

        self::assertSame(
            [['role' => 'user', 'content' => 'Hi'], ['role' => 'assistant', 'content' => 'Hello'], ['role' => 'user', 'content' => 'Translate.']],
            $this->body($client)['messages'] ?? null,
        );
    }

    #[Test]
    public function it_rejects_a_temperature_above_one_without_calling_the_api(): void
    {
        $client = $this->client(200, 'success.json');

        try {
            $this->model($client)->complete(new ChatRequest([new ChatMessage(ChatRole::User, 'Hi')], temperature: 1.5));
            self::fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('1.5', $exception->getMessage());
        }

        self::assertSame([], $client->requests);
    }

    #[Test]
    public function it_accepts_a_temperature_of_one(): void
    {
        $client = $this->client(200, 'success.json');

        $this->model($client)->complete(new ChatRequest([new ChatMessage(ChatRole::User, 'Hi')], temperature: 1.0));

        self::assertSame(1.0, $this->body($client)['temperature'] ?? null);
    }

    #[Test]
    public function it_sends_text_with_broken_encoding_instead_of_failing(): void
    {
        $client = $this->client(200, 'success.json');

        $this->model($client)->complete(new ChatRequest([new ChatMessage(ChatRole::User, "Cze\xC5\x9B\xFF")]));

        $messages = $this->body($client)['messages'] ?? null;
        self::assertIsArray($messages);
        self::assertIsArray($messages[0] ?? null);
        self::assertSame("Cze\u{015B}\u{FFFD}", $messages[0]['content'] ?? null);
    }

    #[Test]
    public function it_wraps_transport_errors_without_the_key(): void
    {
        $model = $this->model(new FakeHttpClient(static fn(): Response => throw new FakeClientException('Could not resolve host; header x-api-key: ' . self::API_KEY)));

        $exception = $this->failure($model);

        self::assertStringStartsWith('Could not reach anthropic at https://api.anthropic.com/v1/messages: ', $exception->getMessage());
        self::assertSame(0, $exception->getCode());
        for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
            self::assertStringNotContainsString(self::API_KEY, $current->getMessage());
        }
    }

    #[Test]
    public function it_never_keeps_the_client_exception_whose_trace_recorded_the_request(): void
    {
        $cause = new FakeClientException('Connection refused');

        $exception = $this->failure($this->model(new FakeHttpClient(static fn(): Response => throw $cause)));

        self::assertNotSame($cause, $exception->getPrevious());
        self::assertInstanceOf(ProviderException::class, $exception->getPrevious());
        self::assertSame(FakeClientException::class . ': Connection refused', $exception->getPrevious()->getMessage());
    }

    #[Test]
    public function it_names_the_configured_model(): void
    {
        self::assertSame('anthropic:claude-opus-5-5', $this->model($this->client(200, 'success.json'))->modelId());
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

    private function model(FakeHttpClient $client): AnthropicChatModel
    {
        $factory = new Psr17Factory();

        return new AnthropicChatModel($client, $factory, $factory, new AnthropicConfig('claude-opus-5-5', self::API_KEY));
    }

    private function client(int $status, string $fixture): FakeHttpClient
    {
        $body = file_get_contents(self::FIXTURES . '/' . $fixture);
        self::assertIsString($body);
        $body = str_replace('sk-ant-test-SECRET-0123456789', self::API_KEY, $body);

        return new FakeHttpClient(static fn(RequestInterface $request): Response => new Response($status, ['Content-Type' => 'application/json'], $body));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonClient(int $status, array $body): FakeHttpClient
    {
        $json = json_encode($body, \JSON_THROW_ON_ERROR);

        return new FakeHttpClient(static fn(): Response => new Response($status, ['Content-Type' => 'application/json'], $json));
    }

    /**
     * @return array<mixed>
     */
    private function body(FakeHttpClient $client): array
    {
        $request = $client->requests[0] ?? self::fail('No request was sent.');
        $body = json_decode((string) $request->getBody(), true, 32, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    private function failure(AnthropicChatModel $model): ProviderException
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
}

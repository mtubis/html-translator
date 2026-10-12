<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Provider\Llm\Bridge\Anthropic;

use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Exception\ProviderException;
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicChatModel;
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicConfig;
use HtmlTranslator\Provider\Llm\ChatMessage;
use HtmlTranslator\Provider\Llm\ChatRequest;
use HtmlTranslator\Provider\Llm\ChatResponse;
use HtmlTranslator\Provider\Llm\ChatRole;
use HtmlTranslator\Provider\Llm\FinishReason;
use HtmlTranslator\Provider\Llm\JsonSchema;
use HtmlTranslator\Tests\Support\FakeClientException;
use HtmlTranslator\Tests\Support\FakeHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
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
        yield 'bad request' => [400, 'error-400.json', 'invalid_request_error', 'anthropic returned HTTP 400: max_tokens: Field required'];
        yield 'wrong key, echoed' => [401, 'error-401.json', 'authentication_error', 'anthropic returned HTTP 401: invalid x-api-key [redacted]'];
        yield 'rate limit' => [429, 'error-429.json', 'rate_limit_error', 'anthropic returned HTTP 429: Number of request tokens has exceeded your per-minute rate limit.'];
        yield 'server error' => [500, 'error-500.json', 'api_error', 'anthropic returned HTTP 500: Internal server error'];
        yield 'overloaded' => [529, 'error-529.json', 'overloaded_error', 'anthropic returned HTTP 529: Overloaded'];
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
    public function it_reports_an_error_without_a_readable_body(): void
    {
        $exception = $this->failure($this->model(new FakeHttpClient(static fn(): Response => new Response(502, [], '<html>Bad gateway</html>'))));

        self::assertSame(502, $exception->statusCode);
        self::assertNull($exception->vendorCode);
        self::assertSame('anthropic returned HTTP 502.', $exception->getMessage());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableAnswers(): iterable
    {
        yield 'not JSON' => ['{"type": "message", "content": ['];
        yield 'a JSON list' => ['[1, 2]'];
        yield 'no content' => ['{"type": "message", "model": "claude-opus-5-5"}'];
        yield 'content that is not a list' => ['{"type": "message", "content": "text"}'];
        yield 'text block without text' => ['{"type": "message", "content": [{"type": "text"}]}'];
    }

    #[Test]
    #[DataProvider('unreadableAnswers')]
    public function it_rejects_a_successful_response_it_cannot_read(string $body): void
    {
        $this->expectException(ProviderException::class);

        $this->model(new FakeHttpClient(static fn(): Response => new Response(200, [], $body)))->complete($this->request());
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
        $config = new AnthropicConfig('claude-opus-5-5', self::API_KEY, effort: 'low', refusalFallbacks: true);

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
        for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
            self::assertStringNotContainsString(self::API_KEY, $current->getMessage());
        }
    }

    #[Test]
    public function it_keeps_a_clean_transport_error_as_the_cause(): void
    {
        $cause = new FakeClientException('Connection refused');

        $exception = $this->failure($this->model(new FakeHttpClient(static fn(): Response => throw $cause)));

        self::assertSame($cause, $exception->getPrevious());
    }

    #[Test]
    public function it_names_the_configured_model(): void
    {
        self::assertSame('anthropic:claude-opus-5-5', $this->model($this->client(200, 'success.json'))->modelId());
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

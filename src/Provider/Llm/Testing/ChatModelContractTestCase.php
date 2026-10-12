<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm\Testing;

use HtmlTranslator\Exception\ProviderException;
use HtmlTranslator\Provider\Llm\ChatMessage;
use HtmlTranslator\Provider\Llm\ChatModelInterface;
use HtmlTranslator\Provider\Llm\ChatRequest;
use HtmlTranslator\Provider\Llm\ChatRole;
use HtmlTranslator\Provider\Llm\FinishReason;
use HtmlTranslator\Provider\Llm\JsonSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Throwable;

/**
 * Every ChatModelInterface implementation must pass this (ADR-0005, ARCHITECTURE §8), also adapters in other packages:
 * it ships in src/ and needs phpunit/phpunit (a suggested dependency). Subclasses reproduce each ChatScenario with
 * their own fake transport and report what they sent; no network, no PSR-7 needed here.
 *
 * Run it on a configuration with JSON mode enabled and API_KEY as the credential.
 */
abstract class ChatModelContractTestCase extends TestCase
{
    /** A credential the adapter is configured with; it must never appear in exception messages or traces. */
    protected const string API_KEY = 'sk-contract-SECRET-0123456789';

    /** An answer schema the contract sends; adapters with structured outputs must pass it on unchanged. */
    private const string ANSWER_SCHEMA = '{"type":"object","properties":{"ok":{"type":"boolean"}},"required":["ok"],"additionalProperties":false}';

    /**
     * A model configured with API_KEY whose far side behaves as described by the scenario. It must answer every call
     * the same way (a test may call it more than once).
     */
    abstract protected function modelFor(ChatScenario $scenario): ChatModelInterface;

    /**
     * The last request the model built by the last modelFor() sent, decoded from the adapter's wire format.
     */
    abstract protected function recordedRequest(): RecordedChatRequest;

    /**
     * Traces with arguments, so a credential passed as an argument would show up in (string) $exception.
     */
    protected function setUp(): void
    {
        parent::setUp();
        ini_set('zend.exception_ignore_args', '0');
    }

    #[Test]
    public function it_sends_messages_roles_options_and_its_model(): void
    {
        $model = $this->modelFor(ChatScenario::success('{"ok":true}', 'contract-model'));

        $model->complete(new ChatRequest(
            [
                new ChatMessage(ChatRole::System, 'You translate HTML units and answer in JSON.'),
                new ChatMessage(ChatRole::User, '{"units":[{"id":"u1","text":"Hello"}]}'),
                new ChatMessage(ChatRole::Assistant, '{"units":[{"id":"u1","text":"Cześć"}]}'),
                new ChatMessage(ChatRole::User, 'Again, please.'),
            ],
            temperature: 0.3,
            jsonResponse: true,
            maxOutputTokens: 256,
            jsonSchema: JsonSchema::fromJson(self::ANSWER_SCHEMA),
        ));

        $recorded = $this->recordedRequest();
        self::assertSame(
            [['system', 'You translate HTML units and answer in JSON.'], ['user', '{"units":[{"id":"u1","text":"Hello"}]}'], ['assistant', '{"units":[{"id":"u1","text":"Cześć"}]}'], ['user', 'Again, please.']],
            $recorded->messages,
        );
        self::assertSame(0.3, $recorded->temperature);
        self::assertTrue($recorded->jsonResponse);
        self::assertSame(256, $recorded->maxOutputTokens);
        self::assertNotNull($recorded->model, 'The adapter must send its configured model.');
        self::assertNotSame('', $recorded->model);
        self::assertStringContainsString($recorded->model, $model->modelId(), 'modelId() must name the model that is sent.');
        if (null !== $recorded->jsonSchema) {
            self::assertJsonStringEqualsJsonString(self::ANSWER_SCHEMA, $recorded->jsonSchema, 'An adapter that sends a schema must send the requested one.');
        }
    }

    #[Test]
    public function it_leaves_optional_settings_out_when_not_given(): void
    {
        $this->modelFor(ChatScenario::success('ok', 'contract-model'))->complete($this->request());

        $recorded = $this->recordedRequest();
        self::assertNull($recorded->temperature);
        self::assertFalse($recorded->jsonResponse);
        self::assertNull($recorded->maxOutputTokens);
        self::assertNull($recorded->jsonSchema);
    }

    #[Test]
    public function it_has_a_stable_model_id(): void
    {
        $model = $this->modelFor(ChatScenario::success('ok', 'contract-model'));

        self::assertNotSame('', trim($model->modelId()));
        self::assertSame($model->modelId(), $model->modelId());
    }

    #[Test]
    public function it_returns_content_model_and_token_counts(): void
    {
        $response = $this->modelFor(ChatScenario::success('{"u1":"Cześć"}', 'contract-model-2026', 120, 45))->complete($this->request());

        self::assertSame('{"u1":"Cześć"}', $response->content);
        self::assertSame('contract-model-2026', $response->model);
        self::assertSame(120, $response->inputTokens);
        self::assertSame(45, $response->outputTokens);
    }

    #[Test]
    public function it_falls_back_to_its_model_and_no_token_counts_when_the_server_sends_none(): void
    {
        $response = $this->modelFor(ChatScenario::success('ok', null, finishReason: null))->complete($this->request());

        self::assertNotSame('', trim($response->model));
        self::assertNull($response->inputTokens);
        self::assertNull($response->outputTokens);
        self::assertNull($response->finishReason);
    }

    /**
     * @return iterable<string, array{FinishReason}>
     */
    public static function finishReasons(): iterable
    {
        foreach (FinishReason::cases() as $reason) {
            yield $reason->name => [$reason];
        }
    }

    #[Test]
    #[DataProvider('finishReasons')]
    public function it_maps_finish_reasons(FinishReason $reason): void
    {
        self::assertSame($reason, $this->modelFor(ChatScenario::success('ok', 'contract-model', finishReason: $reason))->complete($this->request())->finishReason);
    }

    #[Test]
    public function it_can_be_called_more_than_once(): void
    {
        $model = $this->modelFor(ChatScenario::success('ok', 'contract-model'));

        $first = $model->complete($this->request('First'));
        $second = $model->complete($this->request('Second'));

        self::assertSame('ok', $first->content);
        self::assertSame('ok', $second->content);
        self::assertSame([['user', 'Second']], $this->recordedRequest()->messages);
    }

    /**
     * @return iterable<string, array{int, ?string, bool}>
     */
    public static function httpErrors(): iterable
    {
        yield 'bad request' => [400, 'invalid_request_error', true];
        yield 'unauthorized' => [401, 'invalid_api_key', true];
        yield 'rate limited' => [429, 'rate_limit_exceeded', true];
        yield 'server error without code' => [500, null, true];
        yield 'proxy page instead of JSON' => [502, null, false];
    }

    #[Test]
    #[DataProvider('httpErrors')]
    public function it_reports_http_errors_with_status_and_vendor_code(int $statusCode, ?string $vendorCode, bool $jsonErrorBody): void
    {
        $exception = $this->failureOf(ChatScenario::httpError($statusCode, $vendorCode, 'Something went wrong for key ' . static::API_KEY, $jsonErrorBody));

        self::assertSame($statusCode, $exception->statusCode);
        self::assertSame($vendorCode, $exception->vendorCode);
        $this->assertNoCredentials($exception);
    }

    #[Test]
    public function it_reports_a_malformed_body(): void
    {
        $this->assertNoCredentials($this->failureOf(ChatScenario::malformedBody()));
    }

    #[Test]
    public function it_reports_a_body_of_unexpected_shape(): void
    {
        $this->assertNoCredentials($this->failureOf(ChatScenario::unexpectedShape()));
    }

    #[Test]
    public function it_wraps_transport_errors_without_leaking_credentials(): void
    {
        $exception = $this->failureOf(ChatScenario::transportError('cURL error 7: Failed to connect to https://api.example.test/v1/chat?key=' . static::API_KEY));

        self::assertNotNull($exception->getPrevious(), 'The transport error must be kept as the previous exception.');
        $this->assertNoCredentials($exception);
    }

    #[Test]
    public function it_does_not_keep_a_transport_exception_that_holds_the_request(): void
    {
        $exception = $this->failureOf(ChatScenario::networkError('Operation timed out after 30001 milliseconds'));

        for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
            self::assertNotInstanceOf(RequestExceptionInterface::class, $current, 'A cause that holds the request (and its credential headers) must not be kept.');
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $current, 'A cause that holds the request (and its credential headers) must not be kept.');
        }

        self::assertStringContainsString('Operation timed out', $exception->getMessage());
        $this->assertNoCredentials($exception);
    }

    private function failureOf(ChatScenario $scenario): ProviderException
    {
        try {
            $this->modelFor($scenario)->complete($this->request());
        } catch (ProviderException $exception) {
            return $exception;
        }

        self::fail(\sprintf('Scenario %s must raise ProviderException.', $scenario->kind->name));
    }

    private function assertNoCredentials(Throwable $exception): void
    {
        for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
            self::assertStringNotContainsString(static::API_KEY, $current->getMessage(), 'Exception messages must not contain credentials.');
            self::assertStringNotContainsString(static::API_KEY, $current->getTraceAsString(), 'Exception traces must not contain credentials (#[\SensitiveParameter]).');
        }
    }

    private function request(string $content = 'Hello'): ChatRequest
    {
        return new ChatRequest([new ChatMessage(ChatRole::User, $content)]);
    }
}

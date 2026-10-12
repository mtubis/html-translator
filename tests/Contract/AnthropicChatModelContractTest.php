<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Contract;

use HtmlTranslator\Exception\ProviderException;
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicChatModel;
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicConfig;
use HtmlTranslator\Provider\Llm\ChatMessage;
use HtmlTranslator\Provider\Llm\ChatModelInterface;
use HtmlTranslator\Provider\Llm\ChatRequest;
use HtmlTranslator\Provider\Llm\ChatResponse;
use HtmlTranslator\Provider\Llm\FinishReason;
use HtmlTranslator\Provider\Llm\JsonSchema;
use HtmlTranslator\Provider\Llm\Testing\ChatModelContractTestCase;
use HtmlTranslator\Provider\Llm\Testing\ChatScenario;
use HtmlTranslator\Provider\Llm\Testing\ChatScenarioKind;
use HtmlTranslator\Provider\Llm\Testing\RecordedChatRequest;
use HtmlTranslator\Tests\Support\FakeClientException;
use HtmlTranslator\Tests\Support\FakeHttpClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * The fake PSR-18 client answers like the Anthropic Messages API would in each scenario.
 */
#[CoversClass(AnthropicChatModel::class)]
#[UsesClass(AnthropicConfig::class)]
#[UsesClass(ChatModelContractTestCase::class)]
#[UsesClass(ChatScenario::class)]
#[UsesClass(RecordedChatRequest::class)]
#[UsesClass(ChatRequest::class)]
#[UsesClass(ChatMessage::class)]
#[UsesClass(ChatResponse::class)]
#[UsesClass(JsonSchema::class)]
#[UsesClass(ProviderException::class)]
final class AnthropicChatModelContractTest extends ChatModelContractTestCase
{
    private const string MODEL = 'contract-configured-model';

    private const int DEFAULT_MAX_OUTPUT_TOKENS = 16000;

    private ?FakeHttpClient $client = null;

    /**
     * Anthropic has no content filter distinct from a refusal (stop_reason "refusal"), so FinishReason::ContentFilter
     * cannot come from this API.
     *
     * @return iterable<string, array{FinishReason}>
     */
    public static function finishReasons(): iterable
    {
        foreach (parent::finishReasons() as $name => $arguments) {
            if (FinishReason::ContentFilter !== $arguments[0]) {
                yield $name => $arguments;
            }
        }
    }

    protected function modelFor(ChatScenario $scenario): ChatModelInterface
    {
        $this->client = new FakeHttpClient(static fn(): Response => match ($scenario->kind) {
            ChatScenarioKind::Success => self::json(200, self::message($scenario)),
            ChatScenarioKind::HttpError => $scenario->jsonErrorBody
                ? self::json($scenario->statusCode ?? 500, ['type' => 'error', 'error' => array_filter(['type' => $scenario->vendorCode, 'message' => $scenario->errorMessage], static fn(?string $value): bool => null !== $value), 'request_id' => 'req_contract'])
                : new Response($scenario->statusCode ?? 502, ['Content-Type' => 'text/html'], '<html><body><h1>502 Bad Gateway</h1>' . $scenario->errorMessage . '</body></html>'),
            ChatScenarioKind::MalformedBody => new Response(200, ['Content-Type' => 'application/json'], '{"type": "message", "content": ['),
            ChatScenarioKind::UnexpectedShape => self::json(200, ['type' => 'message', 'role' => 'assistant']),
            ChatScenarioKind::TransportError => throw new FakeClientException($scenario->errorMessage ?? 'Connection refused'),
        });
        $factory = new Psr17Factory();

        return new AnthropicChatModel($this->client, $factory, $factory, new AnthropicConfig(self::MODEL, self::API_KEY, baseUrl: 'https://api.example.test/v1', defaultMaxOutputTokens: self::DEFAULT_MAX_OUTPUT_TOKENS));
    }

    protected function recordedRequest(): RecordedChatRequest
    {
        $requests = $this->client->requests ?? [];
        $request = $requests[\count($requests) - 1] ?? self::fail('No request was recorded.');
        $body = json_decode((string) $request->getBody(), true, 32, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsList($body['messages'] ?? null);

        $messages = [];
        if (isset($body['system'])) {
            self::assertIsString($body['system']);
            $messages[] = ['system', $body['system']];
        }

        foreach ($body['messages'] as $message) {
            self::assertIsArray($message);
            self::assertIsString($message['role'] ?? null);
            self::assertIsString($message['content'] ?? null);
            $messages[] = [$message['role'], $message['content']];
        }

        $temperature = $body['temperature'] ?? null;
        $maxTokens = $body['max_tokens'] ?? null;
        $model = $body['model'] ?? null;
        $format = \is_array($body['output_config'] ?? null) ? $body['output_config']['format'] ?? null : null;
        self::assertTrue(null === $temperature || \is_float($temperature) || \is_int($temperature));
        self::assertIsInt($maxTokens, 'The Messages API requires max_tokens.');
        self::assertTrue(null === $model || \is_string($model));
        self::assertTrue(null === $format || \is_array($format));

        $schema = null;
        if (\is_array($format)) {
            self::assertSame('json_schema', $format['type'] ?? null);
            $schema = json_encode($format['schema'] ?? null, \JSON_THROW_ON_ERROR);
        }

        return new RecordedChatRequest(
            $messages,
            null === $temperature ? null : (float) $temperature,
            // Only a schema enforces JSON here (ADR-0014): the contract sends one, so a missing format fails it.
            null !== $format,
            // The API requires a limit: the adapter's default stands for "the request set none".
            self::DEFAULT_MAX_OUTPUT_TOKENS === $maxTokens ? null : $maxTokens,
            $model,
            $schema,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function message(ChatScenario $scenario): array
    {
        $message = [
            'id' => 'msg_contract',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => $scenario->content]],
        ];

        $stopReason = match ($scenario->finishReason) {
            FinishReason::Stop => 'end_turn',
            FinishReason::Length => 'max_tokens',
            FinishReason::Refusal => 'refusal',
            FinishReason::Other => 'tool_use',
            FinishReason::ContentFilter => throw new LogicException('No Anthropic equivalent; excluded in finishReasons().'),
            null => null,
        };
        if (null !== $stopReason) {
            $message['stop_reason'] = $stopReason;
        }

        if (null !== $scenario->model) {
            $message['model'] = $scenario->model;
        }

        if (null !== $scenario->inputTokens || null !== $scenario->outputTokens) {
            $message['usage'] = ['input_tokens' => $scenario->inputTokens, 'output_tokens' => $scenario->outputTokens];
        }

        return $message;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function json(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, \JSON_THROW_ON_ERROR));
    }
}

<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Contract;

use HtmlTranslator\Exception\ProviderException;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\OpenAiCompatibleChatModel;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\OpenAiCompatibleConfig;
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
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * The fake PSR-18 client answers like the OpenAI Chat Completions API would in each scenario.
 */
#[CoversClass(OpenAiCompatibleChatModel::class)]
#[UsesClass(OpenAiCompatibleConfig::class)]
#[UsesClass(ChatModelContractTestCase::class)]
#[UsesClass(ChatScenario::class)]
#[UsesClass(RecordedChatRequest::class)]
#[UsesClass(ChatRequest::class)]
#[UsesClass(ChatMessage::class)]
#[UsesClass(ChatResponse::class)]
#[UsesClass(ProviderException::class)]
#[UsesClass(JsonSchema::class)]
final class OpenAiCompatibleChatModelContractTest extends ChatModelContractTestCase
{
    private const string MODEL = 'contract-configured-model';

    private ?FakeHttpClient $client = null;

    protected function modelFor(ChatScenario $scenario): ChatModelInterface
    {
        $this->client = new FakeHttpClient(static fn(): Response => match ($scenario->kind) {
            ChatScenarioKind::Success => self::json(200, self::completion($scenario)),
            ChatScenarioKind::HttpError => $scenario->jsonErrorBody
                ? self::json($scenario->statusCode ?? 500, ['error' => ['message' => $scenario->errorMessage, 'type' => null, 'param' => null, 'code' => $scenario->vendorCode]])
                : new Response($scenario->statusCode ?? 502, ['Content-Type' => 'text/html'], '<html><body><h1>502 Bad Gateway</h1>' . $scenario->errorMessage . '</body></html>'),
            ChatScenarioKind::MalformedBody => new Response(200, ['Content-Type' => 'application/json'], '{"choices": [{"message": '),
            ChatScenarioKind::UnexpectedShape => self::json(200, ['object' => 'chat.completion', 'choices' => []]),
            ChatScenarioKind::TransportError => throw new FakeClientException($scenario->errorMessage ?? 'Connection refused'),
        });
        $factory = new Psr17Factory();

        return new OpenAiCompatibleChatModel($this->client, $factory, $factory, new OpenAiCompatibleConfig('contract', 'https://api.example.test/v1', self::MODEL, self::API_KEY));
    }

    protected function recordedRequest(): RecordedChatRequest
    {
        $requests = $this->client->requests ?? [];
        $request = $requests[\count($requests) - 1] ?? self::fail('No request was recorded.');
        $body = json_decode((string) $request->getBody(), true, 16, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsList($body['messages'] ?? null);

        $messages = [];
        foreach ($body['messages'] as $message) {
            self::assertIsArray($message);
            self::assertIsString($message['role'] ?? null);
            self::assertIsString($message['content'] ?? null);
            $messages[] = [$message['role'], $message['content']];
        }

        $temperature = $body['temperature'] ?? null;
        $maxTokens = $body['max_tokens'] ?? null;
        $model = $body['model'] ?? null;
        self::assertTrue(null === $temperature || \is_float($temperature) || \is_int($temperature));
        self::assertTrue(null === $maxTokens || \is_int($maxTokens));
        self::assertTrue(null === $model || \is_string($model));

        return new RecordedChatRequest(
            $messages,
            null === $temperature ? null : (float) $temperature,
            ['type' => 'json_object'] === ($body['response_format'] ?? null),
            $maxTokens,
            $model,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function completion(ChatScenario $scenario): array
    {
        $message = FinishReason::Refusal === $scenario->finishReason
            ? ['role' => 'assistant', 'content' => null, 'refusal' => $scenario->content]
            : ['role' => 'assistant', 'content' => $scenario->content];
        $choice = ['index' => 0, 'message' => $message];

        $finishReason = match ($scenario->finishReason) {
            FinishReason::Stop, FinishReason::Refusal => 'stop',
            FinishReason::Length => 'length',
            FinishReason::ContentFilter => 'content_filter',
            FinishReason::Other => 'tool_calls',
            null => null,
        };
        if (null !== $finishReason) {
            $choice['finish_reason'] = $finishReason;
        }

        $completion = ['object' => 'chat.completion', 'choices' => [$choice]];
        if (null !== $scenario->model) {
            $completion['model'] = $scenario->model;
        }

        if (null !== $scenario->inputTokens || null !== $scenario->outputTokens) {
            $completion['usage'] = ['prompt_tokens' => $scenario->inputTokens, 'completion_tokens' => $scenario->outputTokens];
        }

        return $completion;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function json(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, \JSON_THROW_ON_ERROR));
    }
}

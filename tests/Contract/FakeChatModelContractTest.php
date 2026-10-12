<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Contract;

use HtmlTranslator\Exception\ProviderException;
use HtmlTranslator\Provider\Llm\ChatMessage;
use HtmlTranslator\Provider\Llm\ChatModelInterface;
use HtmlTranslator\Provider\Llm\ChatRequest;
use HtmlTranslator\Provider\Llm\ChatResponse;
use HtmlTranslator\Provider\Llm\JsonSchema;
use HtmlTranslator\Provider\Llm\Testing\ChatModelContractTestCase;
use HtmlTranslator\Provider\Llm\Testing\ChatScenario;
use HtmlTranslator\Provider\Llm\Testing\ChatScenarioKind;
use HtmlTranslator\Provider\Llm\Testing\FakeChatModel;
use HtmlTranslator\Provider\Llm\Testing\RecordedChatRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;

/**
 * Proves the contract base is usable: scenarios become answers and exceptions of FakeChatModel, as a real adapter's
 * fake transport would produce them (including redacting the credential from a transport error).
 */
#[CoversClass(FakeChatModel::class)]
#[UsesClass(ChatRequest::class)]
#[UsesClass(ChatMessage::class)]
#[UsesClass(ChatResponse::class)]
#[UsesClass(ProviderException::class)]
#[UsesClass(ChatScenario::class)]
#[UsesClass(RecordedChatRequest::class)]
#[UsesClass(ChatModelContractTestCase::class)]
#[UsesClass(JsonSchema::class)]
final class FakeChatModelContractTest extends ChatModelContractTestCase
{
    private const string MODEL = 'contract-configured-model';

    private ?FakeChatModel $model = null;

    protected function modelFor(ChatScenario $scenario): ChatModelInterface
    {
        $this->model = FakeChatModel::answering(static fn(ChatRequest $request): ChatResponse => match ($scenario->kind) {
            ChatScenarioKind::Success => new ChatResponse($scenario->content ?? '', $scenario->model ?? self::MODEL, $scenario->inputTokens, $scenario->outputTokens, $scenario->finishReason),
            ChatScenarioKind::HttpError => throw ProviderException::fromHttpResponse($scenario->statusCode ?? 500, 'The chat model returned an error.', $scenario->vendorCode),
            ChatScenarioKind::MalformedBody => throw new ProviderException('The chat model returned a body that is not valid JSON.'),
            ChatScenarioKind::UnexpectedShape => throw new ProviderException('The chat model returned JSON without an answer.'),
            ChatScenarioKind::TransportError => throw new ProviderException('The chat model could not be reached.', 0, new RuntimeException(str_replace(self::API_KEY, '[redacted]', $scenario->errorMessage ?? ''))),
        })->withModelId('fake:' . self::MODEL);

        return $this->model;
    }

    protected function recordedRequest(): RecordedChatRequest
    {
        $requests = $this->model->requests ?? [];
        $request = $requests[\count($requests) - 1] ?? self::fail('No request was recorded.');

        return new RecordedChatRequest(
            array_map(static fn(ChatMessage $message): array => [$message->role->value, $message->content], $request->messages),
            $request->temperature,
            $request->jsonResponse,
            $request->maxOutputTokens,
            self::MODEL,
        );
    }
}

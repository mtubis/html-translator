<?php

declare(strict_types=1);

namespace HtmlTranslator\Tools\Eval;

use HtmlTranslator\Provider\Llm\ChatMessage;
use HtmlTranslator\Provider\Llm\ChatModelInterface;
use HtmlTranslator\Provider\Llm\ChatRequest;
use HtmlTranslator\Provider\Llm\ChatResponse;
use HtmlTranslator\Provider\Llm\ChatRole;

/**
 * Counts calls and tokens of the wrapped model; TranslationStats has no token counts before 0.4.
 */
final class TokenCountingChatModel implements ChatModelInterface
{
    private int $calls = 0;

    private int $callsWithContext = 0;

    private ?int $inputTokens = null;

    private ?int $outputTokens = null;

    public function __construct(
        private readonly ChatModelInterface $model,
    ) {}

    public function modelId(): string
    {
        return $this->model->modelId();
    }

    /**
     * Failed requests count as calls too.
     */
    public function complete(ChatRequest $request): ChatResponse
    {
        ++$this->calls;
        if ($this->hasContextHints($request)) {
            ++$this->callsWithContext;
        }

        $response = $this->model->complete($request);
        if (null !== $response->inputTokens) {
            $this->inputTokens = ($this->inputTokens ?? 0) + $response->inputTokens;
        }

        if (null !== $response->outputTokens) {
            $this->outputTokens = ($this->outputTokens ?? 0) + $response->outputTokens;
        }

        return $response;
    }

    /**
     * Token counts are null while the model reported none.
     *
     * @return array{calls: int, inputTokens: ?int, outputTokens: ?int, callsWithContext: int}
     */
    public function usage(): array
    {
        return ['calls' => $this->calls, 'inputTokens' => $this->inputTokens, 'outputTokens' => $this->outputTokens, 'callsWithContext' => $this->callsWithContext];
    }

    public function reset(): void
    {
        $this->calls = 0;
        $this->callsWithContext = 0;
        $this->inputTokens = null;
        $this->outputTokens = null;
    }

    /**
     * PromptBuilder puts context hints first in the user message (ADR-0013).
     */
    private function hasContextHints(ChatRequest $request): bool
    {
        return array_any($request->messages, static fn(ChatMessage $message): bool => ChatRole::User === $message->role && str_starts_with($message->content, '{"context": '));
    }
}

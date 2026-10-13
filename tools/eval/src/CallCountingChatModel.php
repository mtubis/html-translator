<?php

declare(strict_types=1);

namespace HtmlTranslator\Tools\Eval;

use HtmlTranslator\Provider\Llm\ChatMessage;
use HtmlTranslator\Provider\Llm\ChatModelInterface;
use HtmlTranslator\Provider\Llm\ChatRequest;
use HtmlTranslator\Provider\Llm\ChatResponse;
use HtmlTranslator\Provider\Llm\ChatRole;

/**
 * Counts calls of the wrapped model, which TranslationStats does not report; token counts come from the stats.
 */
final class CallCountingChatModel implements ChatModelInterface
{
    private int $calls = 0;

    private int $callsWithContext = 0;

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

        return $this->model->complete($request);
    }

    /**
     * @return array{calls: int, callsWithContext: int}
     */
    public function counts(): array
    {
        return ['calls' => $this->calls, 'callsWithContext' => $this->callsWithContext];
    }

    public function reset(): void
    {
        $this->calls = 0;
        $this->callsWithContext = 0;
    }

    /**
     * PromptBuilder puts context hints first in the user message (ADR-0013).
     */
    private function hasContextHints(ChatRequest $request): bool
    {
        return array_any($request->messages, static fn(ChatMessage $message): bool => ChatRole::User === $message->role && str_starts_with($message->content, '{"context": '));
    }
}

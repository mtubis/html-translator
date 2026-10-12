<?php

declare(strict_types=1);

namespace HtmlTranslator\Tools\Eval;

use HtmlTranslator\Provider\Llm\ChatModelInterface;
use HtmlTranslator\Provider\Llm\ChatRequest;
use HtmlTranslator\Provider\Llm\ChatResponse;

/**
 * Counts calls and tokens of the wrapped model; TranslationStats has no token counts before 0.4.
 */
final class TokenCountingChatModel implements ChatModelInterface
{
    private int $calls = 0;

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
     * @return array{calls: int, inputTokens: ?int, outputTokens: ?int}
     */
    public function usage(): array
    {
        return ['calls' => $this->calls, 'inputTokens' => $this->inputTokens, 'outputTokens' => $this->outputTokens];
    }

    public function reset(): void
    {
        $this->calls = 0;
        $this->inputTokens = null;
        $this->outputTokens = null;
    }
}

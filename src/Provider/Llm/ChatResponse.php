<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm;

use HtmlTranslator\Exception\InvalidArgumentException;

final readonly class ChatResponse
{
    /**
     * @param string $content the model's answer as text; may be empty, the caller decides what that means
     * @param string $model the model that answered as reported by the vendor, or the configured model when the vendor
     *                      reports none
     * @param int|null $inputTokens prompt tokens, when the vendor reports them
     * @param int|null $outputTokens answer tokens, when the vendor reports them
     * @param FinishReason|null $finishReason why the model stopped, when the vendor reports it
     *
     * @throws InvalidArgumentException when the model name is blank or a token count is negative
     */
    public function __construct(
        public string $content,
        public string $model,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?FinishReason $finishReason = null,
    ) {
        if ('' === trim($model)) {
            throw new InvalidArgumentException('A chat response needs the name of the model that answered.');
        }

        if ((null !== $inputTokens && $inputTokens < 0) || (null !== $outputTokens && $outputTokens < 0)) {
            throw new InvalidArgumentException('Token counts cannot be negative.');
        }
    }
}

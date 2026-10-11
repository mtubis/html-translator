<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm\Testing;

/**
 * What an adapter actually sent, decoded from its own wire format, so the contract can compare it with the ChatRequest.
 */
final readonly class RecordedChatRequest
{
    /**
     * @param list<array{string, string}> $messages role value ("system", "user", "assistant") and content
     * @param string|null $model the model name the adapter sent (vendor format, e.g. "gpt-4o-mini")
     */
    public function __construct(
        public array $messages,
        public ?float $temperature,
        public bool $jsonResponse,
        public ?int $maxOutputTokens,
        public ?string $model,
    ) {}
}

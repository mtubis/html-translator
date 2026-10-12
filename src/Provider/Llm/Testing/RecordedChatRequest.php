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
     * @param int|null $maxOutputTokens the limit the request asked for; an adapter whose API requires a limit records
     *                                  null when it sent its own default because the request set none
     * @param string|null $model the model name the adapter sent (vendor format, e.g. "gpt-4o-mini")
     * @param string|null $jsonSchema JSON of the schema the adapter sent; null when it sent none (adapters without
     *                                structured outputs ignore ChatRequest::$jsonSchema)
     */
    public function __construct(
        public array $messages,
        public ?float $temperature,
        public bool $jsonResponse,
        public ?int $maxOutputTokens,
        public ?string $model,
        public ?string $jsonSchema = null,
    ) {}
}

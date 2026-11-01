<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm\Testing;

use HtmlTranslator\Exception\ArrayArgument;
use HtmlTranslator\Exception\InvalidArgumentException;

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
     *
     * @throws InvalidArgumentException when $messages is not a list of [role, content] string pairs (adapter tests
     *                                  build it from decoded wire JSON, which PHPStan cannot check)
     */
    public function __construct(
        public array $messages,
        public ?float $temperature,
        public bool $jsonResponse,
        public ?int $maxOutputTokens,
        public ?string $model,
        public ?string $jsonSchema = null,
    ) {
        foreach (ArrayArgument::arrayList('messages', $messages) as $index => $message) {
            if (2 !== \count(ArrayArgument::stringList(\sprintf('messages[%d]', $index), $message))) {
                throw new InvalidArgumentException(\sprintf('messages[%d] must be a [role, content] pair, got %d elements.', $index, \count($message)));
            }
        }
    }
}

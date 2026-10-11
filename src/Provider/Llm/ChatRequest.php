<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm;

use HtmlTranslator\Exception\InvalidArgumentException;

/**
 * One call to a chat model. Vendor-neutral: adapters map it to their wire format and may clamp or reject settings
 * their API does not support (e.g. a temperature above 1).
 *
 * At most one system message, and only as the first message (some vendors keep it outside the conversation), plus
 * at least one user or assistant message.
 */
final readonly class ChatRequest
{
    /**
     * @param list<ChatMessage> $messages the conversation in order
     * @param float|null $temperature 0–2; null leaves the vendor's default
     * @param bool $jsonResponse ask for a JSON object (structured output / JSON mode where available); the answer must be
     *                           an object, not a top-level array, and the messages must mention "JSON"
     * @param int|null $maxOutputTokens upper bound for the answer; null leaves the vendor's default
     *
     * @throws InvalidArgumentException when the messages break the rules above or a setting is out of range
     */
    public function __construct(
        public array $messages,
        public ?float $temperature = null,
        public bool $jsonResponse = false,
        public ?int $maxOutputTokens = null,
    ) {
        if (!array_is_list($messages)) {
            throw new InvalidArgumentException('Chat messages must be a list.');
        }

        $hasConversation = false;
        foreach ($messages as $index => $message) {
            // Checked at runtime too: this is public API for adapter authors.
            if (!$message instanceof ChatMessage) {
                throw new InvalidArgumentException(\sprintf('Chat message #%d is not a ChatMessage.', $index));
            }

            if (ChatRole::System === $message->role && 0 !== $index) {
                throw new InvalidArgumentException('A system message is only allowed as the first message.');
            }

            if (ChatRole::System !== $message->role) {
                $hasConversation = true;
            }
        }

        if (!$hasConversation) {
            throw new InvalidArgumentException('A chat request needs at least one user or assistant message.');
        }

        // NAN compares false with everything, so it needs its own check.
        if (null !== $temperature && (is_nan($temperature) || $temperature < 0.0 || $temperature > 2.0)) {
            throw new InvalidArgumentException(\sprintf('Temperature must be between 0 and 2, got %s.', var_export($temperature, true)));
        }

        if (null !== $maxOutputTokens && $maxOutputTokens < 1) {
            throw new InvalidArgumentException(\sprintf('The output limit must be at least 1 token, got %d.', $maxOutputTokens));
        }
    }
}

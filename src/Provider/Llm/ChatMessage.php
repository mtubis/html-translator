<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm;

use HtmlTranslator\Exception\InvalidArgumentException;

final readonly class ChatMessage
{
    /**
     * @throws InvalidArgumentException when the content is blank
     */
    public function __construct(
        public ChatRole $role,
        public string $content,
    ) {
        if ('' === trim($content)) {
            throw new InvalidArgumentException(\sprintf('A %s message needs content.', $role->value));
        }
    }
}

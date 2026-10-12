<?php

declare(strict_types=1);

namespace HtmlTranslator\Profile;

use HtmlTranslator\Exception\InvalidArgumentException;

/**
 * A source term and the target-language term it must be translated with.
 */
final readonly class GlossaryEntry
{
    /** Keeps the prompt bounded; a term is a word or a short phrase, never a sentence. */
    public const int MAX_TERM_LENGTH = 200;

    private function __construct(
        public string $source,
        public string $target,
    ) {}

    /**
     * @throws InvalidArgumentException when a term is blank, not valid UTF-8, longer than MAX_TERM_LENGTH characters,
     *                                  or contains line breaks or control characters
     */
    public static function of(string $source, string $target): self
    {
        return new self(
            ProfileText::normalize($source, 'Source term', self::MAX_TERM_LENGTH, singleLine: true),
            ProfileText::normalize($target, 'Target term', self::MAX_TERM_LENGTH, singleLine: true),
        );
    }

}

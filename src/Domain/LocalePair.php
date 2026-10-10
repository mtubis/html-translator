<?php

declare(strict_types=1);

namespace HtmlTranslator\Domain;

use HtmlTranslator\Exception\InvalidArgumentException;

/**
 * Source and target locale of a translation; a null source means the model detects it.
 */
final readonly class LocalePair
{
    private function __construct(
        public ?Locale $source,
        public Locale $target,
    ) {}

    /**
     * Variants of one language (e.g. "en" → "en-GB", "sr-Cyrl" → "sr-Latn") are allowed on purpose:
     * they are legitimate regional or script adaptations.
     *
     * @throws InvalidArgumentException when source and target are the same locale
     */
    public static function of(?Locale $source, Locale $target): self
    {
        if ($source instanceof Locale && $source->equals($target)) {
            throw new InvalidArgumentException(\sprintf('Source and target locale are both "%s".', $target->toString()));
        }

        return new self($source, $target);
    }

    public function isAutoDetect(): bool
    {
        return !$this->source instanceof Locale;
    }
}

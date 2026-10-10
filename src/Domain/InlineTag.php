<?php

declare(strict_types=1);

namespace HtmlTranslator\Domain;

use HtmlTranslator\Exception\InvalidArgumentException;

/**
 * A numbered pseudo-tag standing for an inline element inside a translation unit (ADR-0003).
 *
 * Holds no reference to the DOM node; that mapping lives in the Html layer.
 */
final readonly class InlineTag
{
    private function __construct(
        public int $number,
        public InlineTagKind $kind,
    ) {}

    /**
     * @throws InvalidArgumentException when the number is below 1
     */
    public static function of(int $number, InlineTagKind $kind): self
    {
        if ($number < 1) {
            throw new InvalidArgumentException(\sprintf('Inline tag number must be at least 1, got %d.', $number));
        }

        return new self($number, $kind);
    }
}

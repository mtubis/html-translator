<?php

declare(strict_types=1);

namespace HtmlTranslator\Domain;

use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Exception\UntrustedText;
use Stringable;

/**
 * Identifier of a translation unit, stable within one document (e.g. "u1" for blocks, "a1" for attributes).
 *
 * The id travels to the model and back, so the format is deliberately narrow:
 * anything else in a model response is rejected early instead of being matched loosely.
 */
final readonly class UnitId implements Stringable
{
    private const string PATTERN = '/^[a-z][a-z0-9-]*\z/';

    private function __construct(
        private string $value,
    ) {}

    /**
     * @throws InvalidArgumentException when the id is empty or contains characters other than [a-z0-9-]
     */
    public static function fromString(string $id): self
    {
        if (1 !== preg_match(self::PATTERN, $id)) {
            throw new InvalidArgumentException(\sprintf('"%s" is not a valid unit id; expected e.g. "u1" or "a12".', UntrustedText::quote($id)));
        }

        return new self($id);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

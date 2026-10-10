<?php

declare(strict_types=1);

namespace HtmlTranslator\Domain;

use HtmlTranslator\Exception\InvalidArgumentException;

/**
 * One piece of unit text: decoded text, a pseudo-tag (with its number and source spelling) or raw markup.
 * Tag tokens always carry a number ≥ 1; text and raw markup never do.
 */
final readonly class UnitTextToken
{
    private function __construct(
        public UnitTextTokenKind $kind,
        public ?int $number,
        public string $text,
    ) {}

    public static function text(string $decoded): self
    {
        return new self(UnitTextTokenKind::Text, null, $decoded);
    }

    public static function rawMarkup(string $source): self
    {
        return new self(UnitTextTokenKind::RawMarkup, null, $source);
    }

    /**
     * @throws InvalidArgumentException when the number is below 1
     */
    public static function open(int $number, string $source): self
    {
        return new self(UnitTextTokenKind::Open, self::tagNumber($number), $source);
    }

    /**
     * @throws InvalidArgumentException when the number is below 1
     */
    public static function close(int $number, string $source): self
    {
        return new self(UnitTextTokenKind::Close, self::tagNumber($number), $source);
    }

    /**
     * @throws InvalidArgumentException when the number is below 1
     */
    public static function void(int $number, string $source): self
    {
        return new self(UnitTextTokenKind::Void, self::tagNumber($number), $source);
    }

    private static function tagNumber(int $number): int
    {
        if ($number < 1) {
            throw new InvalidArgumentException(\sprintf('Pseudo-tag number must be at least 1, got %d.', $number));
        }

        return $number;
    }
}

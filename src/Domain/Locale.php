<?php

declare(strict_types=1);

namespace HtmlTranslator\Domain;

use HtmlTranslator\Exception\InvalidArgumentException;
use Stringable;

/**
 * A BCP 47 language tag restricted to language[-Script][-REGION], e.g. "pl", "pt-BR", "zh-Hant-TW".
 *
 * Reference implementation of the value-object conventions used in this library:
 * final readonly, private constructor, validating named constructor, canonical form, equals().
 */
final readonly class Locale implements Stringable
{
    private const string PATTERN = '/^(?<language>[a-z]{2,3})(?:-(?<script>[a-z]{4}))?(?:-(?<region>[a-z]{2}|\d{3}))?$/i';

    /** Languages written right-to-left (ISO 639 codes). */
    private const array RTL_LANGUAGES = ['ar', 'arc', 'ckb', 'dv', 'fa', 'he', 'ks', 'ps', 'sd', 'ug', 'ur', 'yi'];

    private function __construct(
        public string $language,
        public ?string $script,
        public ?string $region,
    ) {}

    /**
     * @throws InvalidArgumentException when the tag is not a supported BCP 47 subset
     */
    public static function fromString(string $tag): self
    {
        $normalized = str_replace('_', '-', trim($tag));

        if (1 !== preg_match(self::PATTERN, $normalized, $matches)) {
            throw new InvalidArgumentException(\sprintf('"%s" is not a valid locale; expected e.g. "pl", "pt-BR" or "zh-Hant-TW".', $tag));
        }

        $script = '' !== ($matches['script'] ?? '') ? ucfirst(strtolower($matches['script'])) : null;
        $region = '' !== ($matches['region'] ?? '') ? strtoupper($matches['region']) : null;

        return new self(strtolower($matches['language']), $script, $region);
    }

    public function isRightToLeft(): bool
    {
        if (null !== $this->script) {
            return \in_array($this->script, ['Arab', 'Hebr', 'Thaa', 'Syrc', 'Nkoo', 'Adlm', 'Rohg'], true);
        }

        return \in_array($this->language, self::RTL_LANGUAGES, true);
    }

    public function equals(self $other): bool
    {
        return $this->toString() === $other->toString();
    }

    public function toString(): string
    {
        return implode('-', array_filter([$this->language, $this->script, $this->region], static fn(?string $part): bool => null !== $part));
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}

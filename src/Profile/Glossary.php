<?php

declare(strict_types=1);

namespace HtmlTranslator\Profile;

use HtmlTranslator\Exception\InvalidArgumentException;

/**
 * Terms that must be translated in a fixed way, for one target language (one profile per target).
 */
final readonly class Glossary
{
    /** Keeps the prompt bounded; only the entries that occur in a batch are sent to the model. */
    public const int MAX_ENTRIES = 500;

    /**
     * @param list<GlossaryEntry> $entries
     *
     * @throws InvalidArgumentException when there are too many entries or a source term repeats case-insensitively
     */
    private function __construct(
        private array $entries,
    ) {
        if (\count($entries) > self::MAX_ENTRIES) {
            throw new InvalidArgumentException(\sprintf('A glossary can have at most %d entries, got %d.', self::MAX_ENTRIES, \count($entries)));
        }

        $seen = [];
        foreach ($entries as $entry) {
            $key = mb_strtolower($entry->source);
            if (isset($seen[$key])) {
                throw new InvalidArgumentException(\sprintf('The glossary contains the source term "%s" more than once (case-insensitive).', $entry->source));
            }

            $seen[$key] = true;
        }
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param array<array-key, string> $terms source term → target term; numeric source terms become integer keys in PHP.
     *                                        A non-string target raises a TypeError, so validate untyped config first.
     *
     * @throws InvalidArgumentException when a term is invalid, there are too many entries or a source term repeats
     */
    public static function fromArray(array $terms): self
    {
        $entries = [];
        foreach ($terms as $source => $target) {
            $entries[] = GlossaryEntry::of((string) $source, $target);
        }

        return new self($entries);
    }

    /**
     * @throws InvalidArgumentException when there are too many entries or a source term repeats
     */
    public static function of(GlossaryEntry ...$entries): self
    {
        return new self(array_values($entries));
    }

    /**
     * @throws InvalidArgumentException when the glossary is full or already contains the source term
     */
    public function with(GlossaryEntry $entry): self
    {
        return new self([...$this->entries, $entry]);
    }

    /**
     * @return list<GlossaryEntry> in insertion order
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public function isEmpty(): bool
    {
        return [] === $this->entries;
    }
}

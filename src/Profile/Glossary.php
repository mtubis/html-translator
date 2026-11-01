<?php

declare(strict_types=1);

namespace HtmlTranslator\Profile;

use HtmlTranslator\Exception\ArrayArgument;
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
     * Also for configuration (YAML, PHP arrays): every element is checked at runtime.
     *
     * @param array<array-key, string> $terms source term → target term; numeric source terms become integer keys in PHP,
     *                                        and terms that are exactly "0", "1", … in order make a list, which is
     *                                        rejected: build such a glossary with of()
     *
     * @throws InvalidArgumentException when $terms is a non-empty list instead of a map, a target is not a string, a term
     *                                  is invalid, there are too many entries or a source term repeats
     */
    public static function fromArray(array $terms): self
    {
        // A YAML list of terms instead of a map would otherwise become "0" → first term, "1" → second term.
        if ([] !== $terms && array_is_list($terms)) {
            throw new InvalidArgumentException('terms must map source terms to target terms, got a list.');
        }

        $entries = [];
        foreach (ArrayArgument::stringMap('terms', $terms) as $source => $target) {
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
     * The entries whose source term occurs in one of the texts (TermMatcher rules: case and whitespace insensitive,
     * whole words), so a prompt carries only the terms it needs.
     *
     * @param list<string> $texts plain text, without markup
     *
     * @throws InvalidArgumentException when $texts is not a list of strings
     */
    public function matching(array $texts): self
    {
        $matcher = TermMatcher::in(ArrayArgument::stringList('texts', $texts));

        return new self(array_values(array_filter(
            $this->entries,
            static fn(GlossaryEntry $entry): bool => $matcher->matches($entry->source),
        )));
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

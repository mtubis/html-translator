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

    private const string SCRIPTS_WITHOUT_SPACES = '[\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}\p{Lao}\p{Khmer}\p{Myanmar}]';

    private const string WORD_CHARACTER = '[\p{L}\p{M}\p{N}_]';

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
     * The entries whose source term occurs in one of the texts, so a prompt carries only the terms it needs.
     *
     * Matching ignores case (full case folding) and differences in whitespace, and needs whole words: "art" does not
     * match "party". Scripts written without spaces between words (Chinese, Japanese, Thai, …) have no word boundaries
     * to check, so their terms match anywhere. A term never matches across two texts.
     *
     * @param list<string> $texts plain text, without markup
     */
    public function matching(array $texts): self
    {
        $haystack = implode("\n", array_map(self::fold(...), $texts));

        return new self(array_values(array_filter(
            $this->entries,
            static fn(GlossaryEntry $entry): bool => 1 === preg_match(self::pattern(self::fold($entry->source)), $haystack),
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

    /**
     * Case-folded (so "STRASSE" equals "straße") with every run of whitespace as one space; texts and terms are
     * compared in this form. Unicode normalization (NFC/NFD) is not applied.
     */
    private static function fold(string $text): string
    {
        // preg_replace() fails only on invalid UTF-8; such a text keeps its whitespace and can still be compared.
        return mb_convert_case(preg_replace('/[\s\p{Z}]+/u', ' ', $text) ?? $text, \MB_CASE_FOLD);
    }

    /**
     * Requires a word boundary at each edge of the term that is a word character, unless it is in a script written
     * without spaces; an edge such as the dot of ".NET" or the plus of "C++" needs none ("ASP.NET", "C++20").
     */
    private static function pattern(string $term): string
    {
        $boundedStart = '/^(?!' . self::SCRIPTS_WITHOUT_SPACES . ')' . self::WORD_CHARACTER . '/u';
        $boundedEnd = '/(?!' . self::SCRIPTS_WITHOUT_SPACES . ')' . self::WORD_CHARACTER . '$/u';
        $before = 1 === preg_match($boundedStart, $term) ? '(?<!' . self::WORD_CHARACTER . ')' : '';
        $after = 1 === preg_match($boundedEnd, $term) ? '(?!' . self::WORD_CHARACTER . ')' : '';

        return '/' . $before . preg_quote($term, '/') . $after . '/u';
    }
}

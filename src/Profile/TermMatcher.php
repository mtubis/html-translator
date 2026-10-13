<?php

declare(strict_types=1);

namespace HtmlTranslator\Profile;

/**
 * Finds profile terms (glossary sources, do-not-translate terms) in plain texts, so a prompt carries only the terms
 * it needs (ADR-0012).
 *
 * Matching ignores case (full case folding) and differences in whitespace, and needs whole words: "art" does not
 * match "party". Scripts written without spaces between words (Chinese, Japanese, Thai, …) have no word boundaries
 * to check, so their terms match anywhere. A term never matches across two texts.
 *
 * @internal used by Glossary and PromptBuilder
 */
final readonly class TermMatcher
{
    private const string SCRIPTS_WITHOUT_SPACES = '[\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}\p{Lao}\p{Khmer}\p{Myanmar}]';

    private const string WORD_CHARACTER = '[\p{L}\p{M}\p{N}_]';

    private function __construct(
        private string $haystack,
    ) {}

    /**
     * @param list<string> $texts plain text, without markup
     */
    public static function in(array $texts): self
    {
        return new self(implode("\n", array_map(self::fold(...), $texts)));
    }

    public function matches(string $term): bool
    {
        return 1 === preg_match($this->pattern(self::fold($term)), $this->haystack);
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
    private function pattern(string $term): string
    {
        $boundedStart = '/^(?!' . self::SCRIPTS_WITHOUT_SPACES . ')' . self::WORD_CHARACTER . '/u';
        $boundedEnd = '/(?!' . self::SCRIPTS_WITHOUT_SPACES . ')' . self::WORD_CHARACTER . '$/u';
        $before = 1 === preg_match($boundedStart, $term) ? '(?<!' . self::WORD_CHARACTER . ')' : '';
        $after = 1 === preg_match($boundedEnd, $term) ? '(?!' . self::WORD_CHARACTER . ')' : '';

        return '/' . $before . preg_quote($term, '/') . $after . '/u';
    }
}

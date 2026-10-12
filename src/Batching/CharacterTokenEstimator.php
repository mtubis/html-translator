<?php

declare(strict_types=1);

namespace HtmlTranslator\Batching;

/**
 * Heuristic token estimate from the characters of a unit text, without a tokenizer (ADR-0015).
 *
 * Pseudo-tags and character references split into several tokens, CJK characters take about one token each,
 * characters outside the BMP and in U+2600–U+27BF (emoji, dingbats) two, everything else about a third of a token. On recorded o200k_base
 * counts (golden fixtures and sentences in nine scripts, tests/Integration/TokenEstimatorAccuracyTest) the estimate
 * lies between 0.8 and 2.5 times the real count: it overestimates short labels and CJK most, and underestimates only
 * text dense with diacritics, by up to 20 %.
 */
final readonly class CharacterTokenEstimator implements TokenEstimatorInterface
{
    private const string PSEUDO_TAG = '/<\/?[gx]\d+\/?>/';

    private const string CHARACTER_REFERENCE = '/&(?:[a-z]+|#\d+);/i';

    private const string CJK = '/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u';

    private const string EMOJI = '/[\x{10000}-\x{10FFFF}\x{2600}-\x{27BF}]/u';

    private const int TOKENS_PER_PSEUDO_TAG = 3;

    private const int TOKENS_PER_CHARACTER_REFERENCE = 3;

    private const int TOKENS_PER_EMOJI = 2;

    private const int CHARACTERS_PER_TOKEN = 3;

    public function estimate(string $text): int
    {
        $pseudoTags = $this->count(self::PSEUDO_TAG, $text);
        $rest = preg_replace(self::PSEUDO_TAG, '', $text) ?? $text;
        $references = $this->count(self::CHARACTER_REFERENCE, $rest);
        $rest = preg_replace(self::CHARACTER_REFERENCE, '', $rest) ?? $rest;
        $cjk = $this->count(self::CJK, $rest);
        $emoji = $this->count(self::EMOJI, $rest);
        $others = mb_strlen($rest, 'UTF-8') - $cjk - $emoji;
        // max() keeps the type non-negative; PHPStan does not infer the range of intdiv().
        $otherTokens = max(0, intdiv($others + self::CHARACTERS_PER_TOKEN - 1, self::CHARACTERS_PER_TOKEN));

        return $pseudoTags * self::TOKENS_PER_PSEUDO_TAG
            + $references * self::TOKENS_PER_CHARACTER_REFERENCE
            + $cjk
            + $emoji * self::TOKENS_PER_EMOJI
            + $otherTokens;
    }

    /**
     * Invalid UTF-8 (never produced by the Segmenter) makes the /u patterns fail; it is then counted as other text.
     *
     * @return non-negative-int
     */
    private function count(string $pattern, string $text): int
    {
        $count = preg_match_all($pattern, $text);

        return false === $count ? 0 : $count;
    }
}

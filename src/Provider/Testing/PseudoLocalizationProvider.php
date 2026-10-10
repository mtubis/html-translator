<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Testing;

use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Domain\UnitText;
use HtmlTranslator\Domain\UnitTextToken;
use HtmlTranslator\Domain\UnitTextTokenKind;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Provider\BatchResult;
use HtmlTranslator\Provider\TranslationBatch;
use HtmlTranslator\Provider\TranslationProviderInterface;

/**
 * Deterministic fake translation for tests and demos, no network: "Hello <g1>world</g1>" →
 * "[!! Ĥéļļö <g1>ŵöŕļð</g1> !!]". Only ASCII letters change; pseudo-tags, escapes and everything else stay,
 * so every translation is structurally valid and visibly translated.
 *
 * Character references are re-escaped (ADR-0008): &quot; comes back as ", &amp; stays &amp;. Raw markup in the input
 * (which the core never sends) is passed through, so invalid input stays invalid. The accent table is frozen:
 * golden fixtures depend on it.
 *
 * corruptingEvery() breaks every k-th unit (by the number in its id: u3 → 3, a6 → 6) to exercise corrective retries
 * and fallbacks: a unit with tags loses its last tag, a unit without tags comes back empty. Derived ids such as
 * "u3-1" (text-node fallback pieces) are never corrupted, so the TextNodesOnly fallback can succeed.
 */
final readonly class PseudoLocalizationProvider implements TranslationProviderInterface
{
    private const array ACCENTS = [
        'a' => 'á', 'b' => 'ƀ', 'c' => 'ç', 'd' => 'ð', 'e' => 'é', 'f' => 'ƒ', 'g' => 'ĝ', 'h' => 'ĥ', 'i' => 'í',
        'j' => 'ĵ', 'k' => 'ķ', 'l' => 'ļ', 'm' => 'ɱ', 'n' => 'ñ', 'o' => 'ö', 'p' => 'þ', 'q' => 'ǫ', 'r' => 'ŕ',
        's' => 'š', 't' => 'ţ', 'u' => 'ü', 'v' => 'ṽ', 'w' => 'ŵ', 'x' => 'ẋ', 'y' => 'ý', 'z' => 'ž',
        'A' => 'Á', 'B' => 'Ɓ', 'C' => 'Ç', 'D' => 'Đ', 'E' => 'É', 'F' => 'Ƒ', 'G' => 'Ĝ', 'H' => 'Ĥ', 'I' => 'Í',
        'J' => 'Ĵ', 'K' => 'Ķ', 'L' => 'Ļ', 'M' => 'Ṁ', 'N' => 'Ñ', 'O' => 'Ö', 'P' => 'Þ', 'Q' => 'Ǫ', 'R' => 'Ŕ',
        'S' => 'Š', 'T' => 'Ţ', 'U' => 'Ü', 'V' => 'Ṽ', 'W' => 'Ŵ', 'X' => 'Ẋ', 'Y' => 'Ý', 'Z' => 'Ž',
    ];

    /**
     * @param int $corruptEvery 0 = never corrupt
     * @param bool $corruptRetries also corrupt corrective retries; needs $corruptEvery
     *
     * @throws InvalidArgumentException when $corruptEvery is negative or $corruptRetries is set without it
     */
    public function __construct(
        private int $corruptEvery = 0,
        private bool $corruptRetries = false,
    ) {
        if ($corruptEvery < 0) {
            throw new InvalidArgumentException(\sprintf('Corruption interval cannot be negative, got %d.', $corruptEvery));
        }

        if ($corruptRetries && 0 === $corruptEvery) {
            throw new InvalidArgumentException('Corrupting retries needs a corruption interval.');
        }
    }

    /**
     * @param bool $persistent also corrupt corrective retries (by default a retried unit comes back correct)
     *
     * @throws InvalidArgumentException when $every is below 1
     */
    public static function corruptingEvery(int $every, bool $persistent = false): self
    {
        if ($every < 1) {
            throw new InvalidArgumentException(\sprintf('Corruption interval must be at least 1, got %d.', $every));
        }

        return new self($every, $persistent);
    }

    public function translate(TranslationBatch $batch): BatchResult
    {
        $translations = [];
        foreach ($batch->units as $unit) {
            $id = $unit->id->toString();
            $corrupt = $this->isCorrupted($unit) && ($this->corruptRetries || !isset($batch->corrections[$id]));
            $translations[$id] = $this->pseudoLocalize($unit, $corrupt);
        }

        return new BatchResult($translations);
    }

    private function isCorrupted(TranslationUnit $unit): bool
    {
        return 0 !== $this->corruptEvery
            && 1 === preg_match('/^[a-z]+([0-9]+)\z/', $unit->id->toString(), $match)
            && 0 === (int) $match[1] % $this->corruptEvery;
    }

    private function pseudoLocalize(TranslationUnit $unit, bool $corrupt): string
    {
        $tokens = UnitText::tokenize($unit->text);

        if ($corrupt) {
            $tags = array_keys(array_filter($tokens, static fn(UnitTextToken $token): bool => null !== $token->number));
            if ([] === $tags) {
                return '';
            }

            unset($tokens[$tags[\count($tags) - 1]]);
        }

        $text = '';
        foreach ($tokens as $token) {
            $text .= UnitTextTokenKind::Text === $token->kind ? UnitText::escape(strtr($token->text, self::ACCENTS)) : $token->text;
        }

        return '[!! ' . $text . ' !!]';
    }
}

<?php

declare(strict_types=1);

namespace HtmlTranslator\Domain;

/**
 * The text format of translation units (ADR-0003, ADR-0008): XML-style escaped text with pseudo-tags.
 *
 * Lives in Domain so that both the Html layer (encoding, reassembly) and Validation can use it.
 */
final class UnitText
{
    /** Anything but separators (spaces, incl. U+00A0), control and format characters (zero-width, soft hyphen, BOM). */
    private const string TRANSLATABLE_CHARACTER = '/[^\p{Z}\p{Cc}\p{Cf}]/u';

    /**
     * <gN>, </gN> or <xN/>, anchored at the offset; whitespace is allowed only before > and /> (ADR-0009).
     * Other combinations (</xN>, <xN>, <gN/>) and numbers over six digits do not match and end up as raw markup.
     * (*NO_START_OPT): otherwise PCRE scans ahead for ">" on every call, which makes tokenize() quadratic.
     */
    private const string PSEUDO_TAG = '/(*NO_START_OPT)\G<(\/?)([gx])([1-9][0-9]{0,5})[ \t\n\f\r]*(\/?)>/';

    /** After "<", these characters make an HTML parser start a tag or comment (ADR-0009). */
    private const string MARKUP_START = '/(*NO_START_OPT)\G<[A-Za-z\/!?]/';

    private function __construct() {}

    public static function escape(string $plain): string
    {
        return strtr($plain, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']);
    }

    /**
     * Whether plain (unescaped) text holds anything worth sending to a model.
     */
    public static function hasTranslatableCharacter(string $plain): bool
    {
        return 1 === preg_match(self::TRANSLATABLE_CHARACTER, $plain);
    }

    /**
     * Splits unit text (typically model output) into text and pseudo-tags, decoding character references (ADR-0009).
     * Never throws: anything that is neither text nor a valid pseudo-tag becomes a RawMarkup token.
     *
     * @return list<UnitTextToken>
     */
    public static function tokenize(string $text): array
    {
        $tokens = [];
        $textStart = 0;
        $offset = 0;

        while (false !== ($position = strpos($text, '<', $offset))) {
            if (1 === preg_match(self::PSEUDO_TAG, $text, $match, 0, $position)) {
                // The groups always exist on a match; the defaults only stand in for the shape PHPStan cannot infer
                // through (*NO_START_OPT).
                $source = $match[0] ?? '';
                $tag = self::tagToken($match[1] ?? '', $match[2] ?? '', (int) ($match[3] ?? '0'), $source);

                if ($tag instanceof UnitTextToken) {
                    self::appendText($tokens, $text, $textStart, $position);
                    $tokens[] = $tag;
                    $offset = $textStart = $position + \strlen($source);

                    continue;
                }
            }

            if (1 === preg_match(self::MARKUP_START, $text, $match, 0, $position)) {
                self::appendText($tokens, $text, $textStart, $position);
                $end = strpos($text, '>', $position);
                $raw = false === $end ? substr($text, $position) : substr($text, $position, $end - $position + 1);
                $tokens[] = UnitTextToken::rawMarkup($raw);
                $offset = $textStart = $position + \strlen($raw);

                continue;
            }

            $offset = $position + 1;
        }

        self::appendText($tokens, $text, $textStart, \strlen($text));

        return $tokens;
    }

    private static function tagToken(string $closingSlash, string $letter, int $number, string $source): ?UnitTextToken
    {
        $selfClosing = str_ends_with($source, '/>');

        return match (true) {
            'g' === $letter && '' === $closingSlash && !$selfClosing => UnitTextToken::open($number, $source),
            'g' === $letter && '/' === $closingSlash && !$selfClosing => UnitTextToken::close($number, $source),
            'x' === $letter && '' === $closingSlash && $selfClosing => UnitTextToken::void($number, $source),
            default => null,
        };
    }

    /**
     * @param list<UnitTextToken> $tokens
     *
     * @param-out list<UnitTextToken> $tokens
     */
    private static function appendText(array &$tokens, string $text, int $start, int $end): void
    {
        if ($end > $start) {
            $tokens[] = UnitTextToken::text(html_entity_decode(substr($text, $start, $end - $start), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
        }
    }
}

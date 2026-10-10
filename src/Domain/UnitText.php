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
}

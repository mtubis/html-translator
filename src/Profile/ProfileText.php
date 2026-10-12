<?php

declare(strict_types=1);

namespace HtmlTranslator\Profile;

use HtmlTranslator\Exception\InvalidArgumentException;

/**
 * Validation shared by the text fields of a profile, so terms and free text follow the same rules.
 *
 * @internal
 */
final class ProfileText
{
    /**
     * Trims Unicode whitespace (text copied from web pages often has non-breaking spaces) and validates the rest.
     *
     * @param bool $singleLine true for terms and the domain, which are rendered one per line in the prompt
     *
     * @throws InvalidArgumentException when the text is not valid UTF-8, blank, longer than $maxLength characters,
     *                                  or, for single-line text, contains line breaks or control characters
     */
    public static function normalize(string $text, string $label, int $maxLength, bool $singleLine): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidArgumentException(\sprintf('%s is not valid UTF-8.', $label));
        }

        $text = mb_trim($text);
        if ('' === $text) {
            throw new InvalidArgumentException(\sprintf('%s cannot be blank.', $label));
        }

        if (mb_strlen($text) > $maxLength) {
            throw new InvalidArgumentException(\sprintf('%s is longer than %d characters.', $label, $maxLength));
        }

        if ($singleLine && 1 === preg_match('/[\p{Cc}\x{2028}\x{2029}]/u', $text)) {
            throw new InvalidArgumentException(\sprintf('%s cannot contain line breaks or control characters.', $label));
        }

        return $text;
    }
}

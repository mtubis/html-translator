<?php

declare(strict_types=1);

namespace HtmlTranslator\Exception;

/**
 * Makes text the library does not control (caller input, configuration, vendor and transport errors, model output)
 * safe to quote in an exception or issue message: bounded, valid UTF-8 and on one line, so it cannot flood logs or
 * forge, colour or reorder log lines.
 *
 * Public API for integrations that report configuration or input errors the way the core does (ADR-0018). quote()
 * guarantees, and changing any of this is a breaking change:
 * - the result is valid UTF-8: each invalid byte sequence becomes U+FFFD;
 * - at most $maxLength characters of the text are kept, the ellipsis (…) included, before escaping;
 * - line feed, carriage return and tab become \n, \r and \t; other C0/C1 controls, DEL, line and paragraph separators
 *   and bidi controls become \u{XXXX}; the result has no line break;
 * - nothing else is changed: no quotes are added and backslashes are not escaped.
 * Secrets are not redacted: never pass one in.
 */
final class UntrustedText
{
    /** Line breaks, other C0/C1 controls, DEL, line and paragraph separators, and bidi controls. */
    private const string UNSAFE = '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{061C}\x{200E}\x{200F}\x{2028}-\x{202E}\x{2066}-\x{2069}]/u';

    private const array ESCAPES = ["\n" => '\n', "\r" => '\r', "\t" => '\t'];

    /**
     * Backslashes are not escaped, so a literal "\n" in the text reads like an escaped line break; neither can break
     * a log line.
     *
     * @param int $maxLength characters of the original text kept, the ellipsis included; values below 1 count as 1
     */
    public static function quote(string $text, int $maxLength = 200): string
    {
        $maxLength = max(1, $maxLength);

        if (!mb_check_encoding($text, 'UTF-8')) {
            // U+FFFD for each invalid sequence, as the HTML parser does; mb_scrub() would depend on a global setting.
            $decoded = json_decode(json_encode($text, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR), flags: \JSON_THROW_ON_ERROR);
            $text = \is_string($decoded) ? $decoded : '';
        }

        // Cut first, so an escape sequence is never cut in half; the result stays bounded (at most 8 bytes per character).
        if (mb_strlen($text) > $maxLength) {
            $text = mb_substr($text, 0, $maxLength - 1) . '…';
        }

        return preg_replace_callback(
            self::UNSAFE,
            static fn(array $match): string => self::ESCAPES[$match[0]] ?? \sprintf('\u{%04X}', mb_ord($match[0])),
            $text,
        ) ?? $text;
    }
}

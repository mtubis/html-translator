<?php

declare(strict_types=1);

namespace HtmlTranslator\Domain;

use HtmlTranslator\Exception\InvalidArgumentException;

/**
 * The smallest piece of text sent to the model: a block's inline content or one attribute value.
 *
 * Surrounding whitespace is kept aside so the model never sees (or loses) it; it is restored on reassembly.
 */
final readonly class TranslationUnit
{
    /**
     * ASCII whitespace as defined by the HTML spec. U+00A0 (&nbsp;) is deliberately not included:
     * HTML does not collapse it, so it is content.
     */
    private const string HTML_WHITESPACE = " \t\n\f\r";

    /**
     * @param list<InlineTag> $inlineTags
     */
    private function __construct(
        public UnitId $id,
        public UnitKind $kind,
        public string $text,
        public array $inlineTags,
        public string $leadingWhitespace,
        public string $trailingWhitespace,
    ) {}

    /**
     * @param string $text unit text with pseudo-tags; surrounding HTML whitespace is split off
     * @param list<InlineTag> $inlineTags
     *
     * @throws InvalidArgumentException when the text is blank, tag numbers repeat,
     *                                  or a plain-text unit (attribute, title) has inline tags
     */
    public static function fromText(UnitId $id, UnitKind $kind, string $text, array $inlineTags = []): self
    {
        $trimmed = trim($text, self::HTML_WHITESPACE);

        if ('' === $trimmed) {
            throw new InvalidArgumentException(\sprintf('Unit "%s" has no text to translate.', $id->toString()));
        }

        if (UnitKind::Block !== $kind && [] !== $inlineTags) {
            throw new InvalidArgumentException(\sprintf('Unit "%s" of kind %s is plain text and cannot contain inline tags.', $id->toString(), $kind->name));
        }

        $numbers = array_map(static fn(InlineTag $tag): int => $tag->number, $inlineTags);
        foreach (array_count_values($numbers) as $number => $count) {
            if ($count > 1) {
                throw new InvalidArgumentException(\sprintf('Unit "%s" contains inline tag number %d more than once.', $id->toString(), $number));
            }
        }

        $leadingLength = strspn($text, self::HTML_WHITESPACE);

        return new self(
            $id,
            $kind,
            $trimmed,
            $inlineTags,
            substr($text, 0, $leadingLength),
            substr($text, $leadingLength + \strlen($trimmed)),
        );
    }
}

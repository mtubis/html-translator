<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Documentation;

use UnexpectedValueException;

/**
 * Reads the PHP snippets of a Markdown file in document order.
 */
final class MarkdownSnippets
{
    /** A fenced block: its language and its content, without the fences. */
    private const string FENCE = '/^```(\w*)[^\n]*\n(.*?)^```[ \t]*$/ms';

    private function __construct() {}

    /**
     * @return list<Snippet>
     */
    public static function fromFile(string $path): array
    {
        $markdown = @file_get_contents($path);
        if (false === $markdown) {
            throw new UnexpectedValueException(\sprintf('Cannot read "%s".', $path));
        }

        return self::fromMarkdown($markdown);
    }

    /**
     * @return list<Snippet>
     */
    public static function fromMarkdown(string $markdown): array
    {
        preg_match_all(self::FENCE, $markdown, $blocks, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);

        $snippets = [];
        $previousPhpEnd = null;
        foreach ($blocks as [$block, $language, $content]) {
            $start = $block[1];
            // A text block belongs to the PHP block right before it, with nothing but blank lines in between.
            $followsPhp = null !== $previousPhpEnd && '' === trim(substr($markdown, $previousPhpEnd, $start - $previousPhpEnd));
            $previousPhpEnd = null;

            if ('php' === $language[0]) {
                $snippets[] = new Snippet($content[0], substr_count($markdown, "\n", 0, $start) + 1);
                $previousPhpEnd = $start + \strlen($block[0]);
            } elseif ('text' === $language[0]) {
                // An unpaired output block would never be compared, so it is an error rather than prose.
                $last = $followsPhp ? array_pop($snippets) : null;
                if (null === $last) {
                    throw new UnexpectedValueException(\sprintf('The text block at line %d does not directly follow a PHP block.', substr_count($markdown, "\n", 0, $start) + 1));
                }
                $snippets[] = new Snippet($last->code, $last->line, $content[0]);
            }
        }

        return $snippets;
    }
}

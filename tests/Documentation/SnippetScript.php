<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Documentation;

use UnexpectedValueException;

/**
 * Turns the snippets of one Markdown file into one PHP script, so later snippets see the variables and imports of
 * earlier ones, as a reader does.
 *
 * Each import stays in the snippet that first has it, so a snippet that relies on a later import fails as it would for
 * a reader; repeated imports are dropped, since PHP rejects an import repeated in the same file. Between snippets the
 * script prints SEPARATOR, so its output can be split per snippet.
 */
final class SnippetScript
{
    public const string SEPARATOR = "\x1E";

    /** A top-level class, function or constant import on a line of its own (a closure's "use (" is not one). */
    private const string IMPORT = '/^use\s+(?:function\s+|const\s+)?\\\\?[A-Za-z_][\w\\\\]*(?:\s+as\s+\w+)?\s*;[ \t]*\R?/m';

    private function __construct() {}

    /**
     * @param list<Snippet> $snippets
     * @param string $prelude code without imports that sets up what the documentation takes as given
     * @param string $postlude code without imports run after the last snippet, e.g. checks that print only on failure
     * @param array<string, string> $substitutions exact phrases of the snippets and the code that replaces them; each
     *                                             must occur, so the documentation cannot drift away from the test
     *
     * @throws UnexpectedValueException when a substitution matches no snippet
     */
    public static function build(array $snippets, string $autoloader, string $prelude, array $substitutions = [], string $postlude = ''): string
    {
        $seen = [];
        $bodies = [];
        foreach ($snippets as $snippet) {
            $bodies[] = preg_replace_callback(self::IMPORT, static function (array $match) use (&$seen): string {
                $import = trim($match[0]);
                if (isset($seen[$import])) {
                    return '';
                }
                $seen[$import] = true;

                return $match[0];
            }, $snippet->code) ?? throw new UnexpectedValueException('Cannot remove the repeated imports of a snippet.');
        }

        $code = implode(\sprintf("\necho %s;\n", var_export(self::SEPARATOR, true)), $bodies);
        foreach ($substitutions as $phrase => $replacement) {
            if (!str_contains($code, $phrase)) {
                throw new UnexpectedValueException(\sprintf('No snippet contains "%s".', $phrase));
            }
            $code = str_replace($phrase, $replacement, $code);
        }

        return "<?php\n\ndeclare(strict_types=1);\n\n"
            . \sprintf("require %s;\n\n", var_export($autoloader, true))
            . $prelude . "\n\n"
            . $code
            . ('' === $postlude ? '' : "\n" . $postlude . "\n");
    }
}

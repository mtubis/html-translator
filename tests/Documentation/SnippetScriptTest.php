<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Documentation;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * The harness lives in tests/, outside the coverage source, hence CoversNothing.
 */
#[CoversNothing]
final class SnippetScriptTest extends TestCase
{
    #[Test]
    public function it_keeps_first_imports_in_place_drops_repeats_and_separates_the_snippets(): void
    {
        $script = SnippetScript::build(
            [
                new Snippet("use HtmlTranslator\\Domain\\Locale;\nuse function strlen;\n\necho 1;\n", 1),
                new Snippet("use HtmlTranslator\\Domain\\Locale;\n\$f = static function () use (\$x): void {};\necho 2;\n", 9),
            ],
            '/app/vendor/autoload.php',
            '$x = 1;',
        );

        self::assertSame(
            "<?php\n\ndeclare(strict_types=1);\n\nrequire '/app/vendor/autoload.php';\n\n\$x = 1;\n\n"
            . "use HtmlTranslator\\Domain\\Locale;\nuse function strlen;\n\necho 1;\n\necho '\x1E';\n\$f = static function () use (\$x): void {};\necho 2;\n",
            $script,
        );
    }

    #[Test]
    public function it_applies_substitutions(): void
    {
        $script = SnippetScript::build([new Snippet("\$http = HttpClient::create(['timeout' => 60]);\n", 1)], 'autoload.php', '', ["HttpClient::create(['timeout' => 60])" => 'Fake::client()']);

        self::assertStringContainsString('$http = Fake::client();', $script);
    }

    #[Test]
    public function it_appends_the_postlude_after_the_last_snippet(): void
    {
        self::assertStringEndsWith("echo 1;\n\ncheck();\n", SnippetScript::build([new Snippet("echo 1;\n", 1)], 'autoload.php', '', [], 'check();'));
    }

    #[Test]
    public function it_rejects_a_substitution_that_matches_no_snippet(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('No snippet contains "HttpClient::create()".');

        SnippetScript::build([new Snippet("echo 1;\n", 1)], 'autoload.php', '', ['HttpClient::create()' => 'Fake::client()']);
    }
}

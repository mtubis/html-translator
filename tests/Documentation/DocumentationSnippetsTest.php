<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Documentation;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs every PHP snippet of the README and the usage guide, so the examples cannot rot (#62).
 *
 * The snippets of one file run as one script in a separate PHP process, in document order. The real HTTP client of
 * the model examples is swapped for PseudoLocalizingVendorApi, so no snippet needs the network. A ```text block
 * right after a snippet is the output it must print.
 */
#[CoversNothing]
final class DocumentationSnippetsTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../..';

    /**
     * What the documentation takes as given: API keys, a PSR-3 logger, a PSR-6 pool and a page to translate. The
     * logger writes to stderr, which must stay empty, so an example that logs an issue fails.
     */
    private const string PRELUDE = <<<'PHP'
        $_ENV['OPENAI_API_KEY'] = 'sk-documentation-test';
        $_ENV['ANTHROPIC_API_KEY'] = 'sk-ant-documentation-test';
        $logger = new class extends \Psr\Log\AbstractLogger {
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                fwrite(STDERR, sprintf("[%s] %s %s\n", $level, $message, json_encode($context)));
            }
        };
        $cache = new \HtmlTranslator\Tests\Support\InMemoryCachePool();
        $html = '<!DOCTYPE html><html lang="en"><head><title>Getting started</title></head><body><h1>Getting started</h1><p>Install the <strong>library</strong> first.</p></body></html>';
        PHP;

    /** The HTTP client of the model examples, replaced by the offline fake. */
    private const string HTTP_CLIENT = "HttpClient::create(['timeout' => 120])";

    /** The OpenAI model of the README, kept under another name: later snippets replace $model with Claude. */
    private const string OPENAI_MODEL = 'OpenAiCompatibleConfig::openAi($_ENV[\'OPENAI_API_KEY\'], \'gpt-4o-mini\'));';

    /** Translates with the README's OpenAI model too, so its setup is exercised and not only built. */
    private const string OPENAI_CHECK = <<<'PHP'
        $openAiResult = new \HtmlTranslator\HtmlTranslator(new \HtmlTranslator\Provider\Llm\LlmTranslationProvider($openAiModel))
            ->translate($html, \HtmlTranslator\Domain\Locale::fromString('pl'));
        if (!$openAiResult->isComplete()) {
            fwrite(STDERR, "The OpenAI example left units untranslated.\n");
        }
        PHP;

    /**
     * @return iterable<string, array{string, array<string, string>, string}>
     */
    public static function documents(): iterable
    {
        yield 'README.md' => [
            'README.md',
            [
                self::HTTP_CLIENT => '\\' . PseudoLocalizingVendorApi::class . '::client()',
                self::OPENAI_MODEL => self::OPENAI_MODEL . "\n" . '$openAiModel = $model;',
            ],
            self::OPENAI_CHECK,
        ];
        yield 'docs/usage.md' => ['docs/usage.md', [], ''];
    }

    /**
     * @param array<string, string> $substitutions
     */
    #[Test]
    #[DataProvider('documents')]
    public function it_runs_every_snippet(string $document, array $substitutions, string $postlude): void
    {
        $markdown = file_get_contents(self::ROOT . '/' . $document);
        self::assertIsString($markdown);
        $snippets = MarkdownSnippets::fromMarkdown($markdown);
        self::assertNotEmpty($snippets, 'The document has no PHP snippets.');
        $phpFences = preg_match_all('/^[ \t]*(?:```|~~~)[ \t]*php/im', $markdown);
        self::assertIsInt($phpFences);
        self::assertCount(
            $phpFences,
            $snippets,
            'Some PHP blocks would not run: indent them by nothing and fence them with ```php.',
        );

        [$exitCode, $output, $errors] = $this->runScript(SnippetScript::build($snippets, self::ROOT . '/vendor/autoload.php', self::PRELUDE, $substitutions, $postlude));

        self::assertSame('', $errors, 'The snippets wrote to stderr.');
        self::assertSame(0, $exitCode, 'The snippets failed.');

        $outputs = explode(SnippetScript::SEPARATOR, $output);
        self::assertCount(\count($snippets), $outputs);
        foreach ($snippets as $index => $snippet) {
            if (null !== $snippet->expectedOutput) {
                self::assertSame(
                    rtrim($snippet->expectedOutput, "\n"),
                    rtrim($outputs[$index] ?? '', "\n"),
                    \sprintf('The output of the snippet at %s:%d differs from the text block after it.', $document, $snippet->line),
                );
            }
        }
    }

    /**
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private function runScript(string $script): array
    {
        $file = tempnam(sys_get_temp_dir(), 'html-translator-docs-');
        $errorFile = tempnam(sys_get_temp_dir(), 'html-translator-docs-stderr-');
        self::assertIsString($file);
        self::assertIsString($errorFile);
        file_put_contents($file, $script);

        try {
            // stderr goes to a file: two pipes could block each other when a snippet writes a lot to stderr.
            $process = proc_open(
                [\PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=stderr', '-d', 'zend.assertions=1', $file],
                [1 => ['pipe', 'w'], 2 => ['file', $errorFile, 'w']],
                $pipes,
                self::ROOT,
            );
            self::assertIsResource($process);
            $stdout = $pipes[1] ?? self::fail('No stdout pipe.');
            $output = stream_get_contents($stdout);
            fclose($stdout);
            $exitCode = proc_close($process);
            $errors = file_get_contents($errorFile);
        } finally {
            unlink($file);
            unlink($errorFile);
        }

        self::assertIsString($output);
        self::assertIsString($errors);

        return [$exitCode, $output, $errors];
    }
}

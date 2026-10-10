<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Guards ADR-0006: src/ uses only the Dom\ namespace API, never the legacy libxml HTML 4 parser.
 */
#[CoversNothing]
final class NoLegacyDomTest extends TestCase
{
    /**
     * Case-insensitive because PHP class and function names are; an explicit list because a bare
     * "DOM\w+" would also match Domain.
     */
    private const string LEGACY_API = '/\b(?:DOM(?:Document|DocumentFragment|XPath|Element|Node|NodeList|Text|Comment|Attr|CdataSection|EntityReference|Implementation)\b|simplexml_import_dom\b)|->loadHTML(?:File)?\s*\(/i';

    #[Test]
    public function src_does_not_use_the_legacy_dom_api(): void
    {
        $offenders = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../../src', RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);

            if ($this->usesLegacyApi($source)) {
                $offenders[] = $file->getPathname();
            }
        }

        self::assertSame([], $offenders, 'Legacy DOM API found; use Dom\HTMLDocument (ADR-0006).');
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function samples(): iterable
    {
        yield 'legacy class' => ['<?php new \DOMDocument();', true];
        yield 'legacy class in lower case' => ['<?php new domdocument();', true];
        yield 'legacy loader call' => ['<?php $doc->loadHTML($html);', true];
        yield 'simplexml bridge' => ['<?php simplexml_import_dom($node);', true];
        yield 'native API' => ['<?php Dom\HTMLDocument::createFromString($html); function f(Dom\Element $e) {}', false];
        yield 'Domain namespace' => ['<?php use HtmlTranslator\Domain\Locale;', false];
        yield 'mention in a comment' => ["<?php\n/** Never use DOMDocument here. */\n// nor ->loadHTML()\n", false];
    }

    #[Test]
    #[DataProvider('samples')]
    public function its_check_recognizes_the_legacy_api(string $source, bool $expected): void
    {
        self::assertSame($expected, $this->usesLegacyApi($source));
    }

    private function usesLegacyApi(string $source): bool
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (\is_array($token) && \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= \is_array($token) ? $token[1] : $token;
        }

        return 1 === preg_match(self::LEGACY_API, $code);
    }
}

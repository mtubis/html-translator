<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Html;

use HtmlTranslator\Html\HtmlLoader;
use HtmlTranslator\Html\LoadedDocument;
use HtmlTranslator\Tests\Support\DomAssertions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every file in tests/Fixtures/roundtrip/ must survive load → serialize unchanged in structure.
 * The file name prefix ("document-" or "fragment-") states which kind the loader must detect.
 */
#[CoversClass(HtmlLoader::class)]
#[CoversClass(LoadedDocument::class)]
final class RoundTripTest extends TestCase
{
    use DomAssertions;

    private const string FIXTURES = __DIR__ . '/../../Fixtures/roundtrip';

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function fixtures(): iterable
    {
        $files = glob(self::FIXTURES . '/*.html');
        self::assertNotFalse($files);
        self::assertNotEmpty($files, 'No round-trip fixtures found.');

        foreach ($files as $file) {
            $name = basename($file, '.html');
            self::assertMatchesRegularExpression('/^(document|fragment)-/', $name, 'Fixture name must start with "document-" or "fragment-".');

            yield $name => [$file, str_starts_with($name, 'fragment-')];
        }
    }

    #[Test]
    #[DataProvider('fixtures')]
    public function it_round_trips_the_fixture(string $file, bool $isFragment): void
    {
        $html = file_get_contents($file);
        self::assertIsString($html);

        $loaded = new HtmlLoader()->load($html);

        self::assertSame($isFragment, $loaded->isFragment(), 'Document/fragment detection does not match the fixture name.');

        if ($isFragment) {
            self::assertSameFragmentStructure($html, $loaded->serialize());
        } else {
            self::assertSameDocumentStructure($html, $loaded->serialize());
        }
    }
}

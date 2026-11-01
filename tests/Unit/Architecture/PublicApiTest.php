<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Architecture;

use HtmlTranslator\Tests\Support\PublicApiDump;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Guards ADR-0018: the public API is what tests/Unit/Architecture/public-api.txt lists, and every change to it is a
 * visible diff. After an intended change, run `php scripts/dump-public-api.php` and commit the file.
 */
#[CoversNothing]
final class PublicApiTest extends TestCase
{
    private const string SNAPSHOT = __DIR__ . '/public-api.txt';

    private const string FIXTURE_NAMESPACE = 'HtmlTranslator\Tests\Unit\Architecture\Fixture\\';

    #[Test]
    public function the_public_api_matches_the_committed_snapshot(): void
    {
        $dump = new PublicApiDump();

        self::assertStringEqualsFile(
            self::SNAPSHOT,
            $dump->dump($dump->classesIn(__DIR__ . '/../../../src', 'HtmlTranslator\\')),
            'The public API changed. If intended, run `php scripts/dump-public-api.php` and commit the diff (ADR-0018).',
        );
    }

    #[Test]
    public function no_public_signature_refers_to_an_internal_class(): void
    {
        $dump = new PublicApiDump();

        self::assertSame([], $dump->internalReferences($dump->classesIn(__DIR__ . '/../../../src', 'HtmlTranslator\\')));
    }

    #[Test]
    public function it_renders_kinds_constants_cases_properties_and_signatures_and_hides_internals(): void
    {
        $expected = <<<'TXT'
            abstract class HtmlTranslator\Tests\Unit\Architecture\Fixture\SampleBase
                public private(set) int $calls
                final public function greet(): string
                public function helpers(): array
                abstract protected function name(): string

            enum HtmlTranslator\Tests\Unit\Architecture\Fixture\SampleMode: string
                case Fast = 'fast'
                case Slow = 'slow'

            final readonly class HtmlTranslator\Tests\Unit\Architecture\Fixture\SampleService
                public const string NAME = 'sample'
                public int $limit
                public ArrayObject $items
                public function __construct(int $limit = 10, HtmlTranslator\Tests\Unit\Architecture\Fixture\SampleMode $mode = \HtmlTranslator\Tests\Unit\Architecture\Fixture\SampleMode::Fast, ArrayObject $items = new \ArrayObject([0 => 1, 1 => 2]))
                public static function create(string ...$tags): HtmlTranslator\Tests\Unit\Architecture\Fixture\SampleService
                public function run(?HtmlTranslator\Tests\Unit\Architecture\Fixture\SampleHelper $helper = NULL): HtmlTranslator\Tests\Unit\Architecture\Fixture\SampleMode

            TXT;

        self::assertSame($expected, new PublicApiDump()->dump($this->fixtureClasses()));
    }

    #[Test]
    public function it_reports_native_and_phpdoc_references_from_public_signatures_to_internal_classes(): void
    {
        self::assertSame(
            [
                self::FIXTURE_NAMESPACE . 'SampleBase::helpers() -> ' . self::FIXTURE_NAMESPACE . 'SampleHelper',
                self::FIXTURE_NAMESPACE . 'SampleService::run() -> ' . self::FIXTURE_NAMESPACE . 'SampleHelper',
            ],
            new PublicApiDump()->internalReferences($this->fixtureClasses()),
        );
    }

    /**
     * @return list<class-string>
     */
    private function fixtureClasses(): array
    {
        return new PublicApiDump()->classesIn(__DIR__ . '/Fixture', self::FIXTURE_NAMESPACE);
    }
}

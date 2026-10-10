<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Domain;

use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Domain\LocalePair;
use HtmlTranslator\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocalePair::class)]
#[UsesClass(Locale::class)]
#[UsesClass(InvalidArgumentException::class)]
final class LocalePairTest extends TestCase
{
    #[Test]
    public function it_holds_source_and_target(): void
    {
        $pair = LocalePair::of(Locale::fromString('en'), Locale::fromString('pl'));

        self::assertSame('en', $pair->source?->toString());
        self::assertSame('pl', $pair->target->toString());
        self::assertFalse($pair->isAutoDetect());
    }

    #[Test]
    public function it_auto_detects_when_source_is_null(): void
    {
        $pair = LocalePair::of(null, Locale::fromString('pl'));

        self::assertNull($pair->source);
        self::assertTrue($pair->isAutoDetect());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function identicalPairs(): iterable
    {
        yield 'same tag' => ['pl', 'pl'];
        yield 'same tag after canonicalization' => ['pt_br', 'pt-BR'];
    }

    #[Test]
    #[DataProvider('identicalPairs')]
    public function it_rejects_identical_source_and_target(string $source, string $target): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf('"%s"', Locale::fromString($target)->toString()));

        LocalePair::of(Locale::fromString($source), Locale::fromString($target));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function regionalAdaptations(): iterable
    {
        yield 'language to regional variant' => ['en', 'en-GB'];
        yield 'between regional variants' => ['pt-PT', 'pt-BR'];
        yield 'between scripts' => ['sr-Cyrl', 'sr-Latn'];
    }

    #[Test]
    #[DataProvider('regionalAdaptations')]
    public function it_allows_adaptation_between_variants_of_one_language(string $source, string $target): void
    {
        $pair = LocalePair::of(Locale::fromString($source), Locale::fromString($target));

        self::assertSame($source, $pair->source?->toString());
        self::assertSame($target, $pair->target->toString());
    }
}

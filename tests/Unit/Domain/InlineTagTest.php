<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Domain;

use HtmlTranslator\Domain\InlineTag;
use HtmlTranslator\Domain\InlineTagKind;
use HtmlTranslator\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InlineTag::class)]
#[UsesClass(InvalidArgumentException::class)]
final class InlineTagTest extends TestCase
{
    #[Test]
    public function it_holds_number_and_kind(): void
    {
        $tag = InlineTag::of(1, InlineTagKind::Paired);

        self::assertSame(1, $tag->number);
        self::assertSame(InlineTagKind::Paired, $tag->kind);
    }

    #[Test]
    public function it_accepts_large_numbers(): void
    {
        self::assertSame(\PHP_INT_MAX, InlineTag::of(\PHP_INT_MAX, InlineTagKind::Opaque)->number);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidNumbers(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    #[Test]
    #[DataProvider('invalidNumbers')]
    public function it_rejects_numbers_below_one(int $number): void
    {
        $this->expectException(InvalidArgumentException::class);

        InlineTag::of($number, InlineTagKind::Void);
    }
}

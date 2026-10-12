<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Domain;

use HtmlTranslator\Domain\UnitId;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Exception\UntrustedText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnitId::class)]
#[UsesClass(InvalidArgumentException::class)]
#[UsesClass(UntrustedText::class)]
final class UnitIdTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function validIds(): iterable
    {
        yield 'block unit' => ['u1'];
        yield 'attribute unit' => ['a12'];
        yield 'single letter' => ['t'];
        yield 'with hyphen' => ['u-1'];
    }

    #[Test]
    #[DataProvider('validIds')]
    public function it_accepts_valid_ids(string $id): void
    {
        self::assertSame($id, UnitId::fromString($id)->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIds(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace only' => [' '];
        yield 'surrounding whitespace' => [' u1 '];
        yield 'upper case' => ['U1'];
        yield 'leading digit' => ['1u'];
        yield 'leading hyphen' => ['-u1'];
        yield 'inner space' => ['u 1'];
        yield 'quote' => ['u"1'];
        yield 'trailing newline' => ["u1\n"];
    }

    #[Test]
    #[DataProvider('invalidIds')]
    public function it_rejects_invalid_ids(string $id): void
    {
        $this->expectException(InvalidArgumentException::class);

        UnitId::fromString($id);
    }

    #[Test]
    public function it_compares_by_value(): void
    {
        self::assertTrue(UnitId::fromString('u1')->equals(UnitId::fromString('u1')));
        self::assertFalse(UnitId::fromString('u1')->equals(UnitId::fromString('u2')));
    }

    #[Test]
    public function it_is_stringable(): void
    {
        self::assertSame('a3', (string) UnitId::fromString('a3'));
    }
}

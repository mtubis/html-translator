<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Domain;

use HtmlTranslator\Domain\UnitText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnitText::class)]
final class UnitTextTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function escaping(): iterable
    {
        yield 'plain' => ['Hello', 'Hello'];
        yield 'ampersand' => ['Tom & Jerry', 'Tom &amp; Jerry'];
        yield 'angle brackets' => ['a < b > c', 'a &lt; b &gt; c'];
        yield 'pseudo-tag lookalike' => ['<g1>x</g1><x2/>', '&lt;g1&gt;x&lt;/g1&gt;&lt;x2/&gt;'];
        yield 'existing entity is escaped again' => ['&amp;', '&amp;amp;'];
        yield 'quotes are untouched' => ['"\'', '"\''];
        yield 'multibyte' => ['Zażółć 😀', 'Zażółć 😀'];
    }

    #[Test]
    #[DataProvider('escaping')]
    public function it_escapes_ampersand_and_angle_brackets(string $plain, string $escaped): void
    {
        self::assertSame($escaped, UnitText::escape($plain));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function translatability(): iterable
    {
        yield 'letter' => ['a', true];
        yield 'digit' => ['7', true];
        yield 'punctuation' => ['!', true];
        yield 'emoji' => ['😀', true];
        yield 'letter among whitespace' => [" \n a \t", true];
        yield 'empty' => ['', false];
        yield 'HTML whitespace' => [" \t\n\f\r", false];
        yield 'no-break space' => ["\u{00A0}", false];
        yield 'em space' => ["\u{2003}", false];
        yield 'zero-width space' => ["\u{200B}", false];
        yield 'byte order mark' => ["\u{FEFF}", false];
        yield 'soft hyphen' => ["\u{00AD}", false];
    }

    #[Test]
    #[DataProvider('translatability')]
    public function it_tells_whether_text_holds_something_to_translate(string $plain, bool $expected): void
    {
        self::assertSame($expected, UnitText::hasTranslatableCharacter($plain));
    }
}

<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Exception;

use HtmlTranslator\Exception\UntrustedText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UntrustedText::class)]
final class UntrustedTextTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function texts(): iterable
    {
        yield 'short text' => ['Invalid key', 'Invalid key'];
        yield 'empty' => ['', ''];
        yield 'ASCII at the limit' => [str_repeat('a', 200), str_repeat('a', 200)];
        yield 'ASCII over the limit' => [str_repeat('a', 201), str_repeat('a', 199) . '…'];
        yield 'multibyte at the limit' => [str_repeat('ż', 200), str_repeat('ż', 200)];
        yield 'multibyte over the limit' => ['b' . str_repeat('ż', 200), 'b' . str_repeat('ż', 198) . '…'];
        yield 'line feed' => ["a\nb", 'a\nb'];
        yield 'carriage return and line feed' => ["a\r\nb", 'a\r\nb'];
        yield 'tab' => ["a\tb", 'a\tb'];
        yield 'NUL' => ["a\0b", 'a\u{0000}b'];
        yield 'ANSI escape' => ["\x1b[31mred", '\u{001B}[31mred'];
        yield 'DEL' => ["a\x7fb", 'a\u{007F}b'];
        yield 'C1 next line' => ["a\u{0085}b", 'a\u{0085}b'];
        yield 'line separator' => ["a\u{2028}b", 'a\u{2028}b'];
        yield 'paragraph separator' => ["a\u{2029}b", 'a\u{2029}b'];
        yield 'bidi override' => ["\u{202E}gnp.exe", '\u{202E}gnp.exe'];
        yield 'bidi isolate' => ["a\u{2066}b\u{2069}", 'a\u{2066}b\u{2069}'];
        yield 'Arabic letter mark' => ["a\u{061C}b", 'a\u{061C}b'];
        yield 'invalid UTF-8' => ["Cze\xC5\x9B\xFF", "Cze\u{015B}\u{FFFD}"];
        yield 'emoji with zero-width joiner' => ["Dev 👩\u{200D}💻 ✓", "Dev 👩\u{200D}💻 ✓"];
        yield 'cut before escaping' => [str_repeat("\n", 300), str_repeat('\n', 199) . '…'];
    }

    #[Test]
    #[DataProvider('texts')]
    public function it_bounds_and_escapes_text(string $text, string $expected): void
    {
        self::assertSame($expected, UntrustedText::quote($text));
    }

    #[Test]
    public function it_takes_another_limit(): void
    {
        self::assertSame('abcd…', UntrustedText::quote('abcdefgh', 5));
        self::assertSame('abcde', UntrustedText::quote('abcde', 5));
        self::assertSame('…', UntrustedText::quote('abcde', 0), 'A limit below 1 counts as 1.');
    }

    #[Test]
    public function it_keeps_a_megabyte_of_hostile_text_short_and_on_one_line(): void
    {
        $quoted = UntrustedText::quote(str_repeat("x\n\x1b[31mFAKE LOG LINE\r\n", 50_000));

        self::assertLessThan(2000, \strlen($quoted));
        self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $quoted);
    }
}

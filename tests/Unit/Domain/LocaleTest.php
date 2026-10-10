<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Domain;

use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Exception\TranslatorException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Locale::class)]
#[CoversClass(InvalidArgumentException::class)]
final class LocaleTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validTags(): iterable
    {
        yield 'language only' => ['pl', 'pl'];
        yield 'three-letter language' => ['fil', 'fil'];
        yield 'language and region' => ['pt-BR', 'pt-BR'];
        yield 'lower-case region is canonicalized' => ['de-ch', 'de-CH'];
        yield 'underscore separator is accepted' => ['en_GB', 'en-GB'];
        yield 'script is title-cased' => ['zh-hant-tw', 'zh-Hant-TW'];
        yield 'numeric region' => ['es-419', 'es-419'];
        yield 'surrounding whitespace is ignored' => ['  fr  ', 'fr'];
    }

    #[Test]
    #[DataProvider('validTags')]
    public function it_canonicalizes_valid_tags(string $input, string $expected): void
    {
        self::assertSame($expected, Locale::fromString($input)->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTags(): iterable
    {
        yield 'empty' => [''];
        yield 'single letter' => ['e'];
        yield 'too long language' => ['engl'];
        yield 'digits in language' => ['e1'];
        yield 'invalid region' => ['en-USA'];
        yield 'trailing separator' => ['en-'];
        yield 'private use extension' => ['en-x-custom'];
    }

    #[Test]
    #[DataProvider('invalidTags')]
    public function it_rejects_invalid_tags(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        Locale::fromString($input);
    }

    #[Test]
    public function its_exception_is_a_translator_exception(): void
    {
        try {
            Locale::fromString('not a locale');
            self::fail('Expected exception was not thrown.');
        } catch (TranslatorException $exception) {
            self::assertStringContainsString('"not a locale"', $exception->getMessage());
        }
    }

    #[Test]
    public function it_exposes_subtags(): void
    {
        $locale = Locale::fromString('zh-Hant-TW');

        self::assertSame('zh', $locale->language);
        self::assertSame('Hant', $locale->script);
        self::assertSame('TW', $locale->region);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function directionality(): iterable
    {
        yield 'Arabic' => ['ar', true];
        yield 'Hebrew with region' => ['he-IL', true];
        yield 'Persian' => ['fa', true];
        yield 'Polish' => ['pl', false];
        yield 'Azerbaijani in Arabic script' => ['az-Arab', true];
        yield 'Uyghur in Latin script' => ['ug-Latn', false];
    }

    #[Test]
    #[DataProvider('directionality')]
    public function it_knows_whether_it_is_right_to_left(string $tag, bool $expected): void
    {
        self::assertSame($expected, Locale::fromString($tag)->isRightToLeft());
    }

    #[Test]
    public function it_compares_by_canonical_form(): void
    {
        self::assertTrue(Locale::fromString('pt_br')->equals(Locale::fromString('pt-BR')));
        self::assertFalse(Locale::fromString('pt-PT')->equals(Locale::fromString('pt-BR')));
    }

    #[Test]
    public function it_is_stringable(): void
    {
        self::assertSame('de-CH', (string) Locale::fromString('de_ch'));
    }
}

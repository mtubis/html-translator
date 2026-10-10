<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Domain;

use HtmlTranslator\Domain\InlineTag;
use HtmlTranslator\Domain\InlineTagKind;
use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Domain\UnitId;
use HtmlTranslator\Domain\UnitKind;
use HtmlTranslator\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TranslationUnit::class)]
#[UsesClass(InlineTag::class)]
#[UsesClass(UnitId::class)]
#[UsesClass(InvalidArgumentException::class)]
final class TranslationUnitTest extends TestCase
{
    #[Test]
    public function it_holds_a_block_unit_with_inline_tags(): void
    {
        $tags = [InlineTag::of(1, InlineTagKind::Paired), InlineTag::of(2, InlineTagKind::Void)];

        $unit = TranslationUnit::fromText(UnitId::fromString('u1'), UnitKind::Block, 'Click <g1>here</g1><x2/>', $tags);

        self::assertSame('u1', $unit->id->toString());
        self::assertSame(UnitKind::Block, $unit->kind);
        self::assertSame('Click <g1>here</g1><x2/>', $unit->text);
        self::assertSame($tags, $unit->inlineTags);
    }

    #[Test]
    public function it_has_no_inline_tags_by_default(): void
    {
        $unit = TranslationUnit::fromText(UnitId::fromString('a1'), UnitKind::Attribute, 'Logo');

        self::assertSame([], $unit->inlineTags);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function surroundingWhitespace(): iterable
    {
        yield 'none' => ['Hello', '', 'Hello', ''];
        yield 'leading and trailing' => ["\n  Hello world \t", "\n  ", 'Hello world', " \t"];
        yield 'all HTML whitespace characters' => [" \t\n\f\rHello \t\n\f\r", " \t\n\f\r", 'Hello', " \t\n\f\r"];
        yield 'inner whitespace is kept' => ['  a  b  ', '  ', 'a  b', '  '];
        yield 'no-break space is content' => ["\u{00A0}Hello\u{00A0}", '', "\u{00A0}Hello\u{00A0}", ''];
        yield 'vertical tab is content' => ["\vHello", '', "\vHello", ''];
        // A CMS-style <p>&nbsp;</p> is a valid unit; filtering such units is the Segmenter's call.
        yield 'no-break space alone is content' => ["\u{00A0}", '', "\u{00A0}", ''];
    }

    #[Test]
    #[DataProvider('surroundingWhitespace')]
    public function it_preserves_surrounding_whitespace_separately(string $input, string $leading, string $text, string $trailing): void
    {
        $unit = TranslationUnit::fromText(UnitId::fromString('u1'), UnitKind::Block, $input);

        self::assertSame($leading, $unit->leadingWhitespace);
        self::assertSame($text, $unit->text);
        self::assertSame($trailing, $unit->trailingWhitespace);
    }

    #[Test]
    public function it_preserves_surrounding_whitespace_of_plain_text_units(): void
    {
        $unit = TranslationUnit::fromText(UnitId::fromString('a1'), UnitKind::Attribute, ' Company logo ');

        self::assertSame(' ', $unit->leadingWhitespace);
        self::assertSame('Company logo', $unit->text);
        self::assertSame(' ', $unit->trailingWhitespace);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankTexts(): iterable
    {
        yield 'empty' => [''];
        yield 'HTML whitespace only' => [" \t\n\f\r"];
    }

    #[Test]
    #[DataProvider('blankTexts')]
    public function it_rejects_blank_text(string $text): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"u7"');

        TranslationUnit::fromText(UnitId::fromString('u7'), UnitKind::Block, $text);
    }

    #[Test]
    public function it_rejects_duplicate_inline_tag_numbers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('number 2');

        TranslationUnit::fromText(UnitId::fromString('u1'), UnitKind::Block, '<g1>a</g1><g2>b</g2><x2/>', [
            InlineTag::of(1, InlineTagKind::Paired),
            InlineTag::of(2, InlineTagKind::Paired),
            InlineTag::of(2, InlineTagKind::Void),
        ]);
    }

    /**
     * @return iterable<string, array{UnitKind}>
     */
    public static function plainTextKinds(): iterable
    {
        yield 'attribute' => [UnitKind::Attribute];
        yield 'title' => [UnitKind::Title];
    }

    #[Test]
    #[DataProvider('plainTextKinds')]
    public function it_rejects_inline_tags_in_plain_text_units(UnitKind $kind): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($kind->name);

        TranslationUnit::fromText(UnitId::fromString('a1'), $kind, 'Logo <x1/>', [InlineTag::of(1, InlineTagKind::Void)]);
    }
}

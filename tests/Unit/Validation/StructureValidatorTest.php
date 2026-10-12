<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Validation;

use HtmlTranslator\Domain\InlineTag;
use HtmlTranslator\Domain\InlineTagKind;
use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Domain\UnitId;
use HtmlTranslator\Domain\UnitKind;
use HtmlTranslator\Domain\UnitText;
use HtmlTranslator\Domain\UnitTextToken;
use HtmlTranslator\Exception\UntrustedText;
use HtmlTranslator\Validation\StructureValidator;
use HtmlTranslator\Validation\TagNesting;
use HtmlTranslator\Validation\ValidationResult;
use HtmlTranslator\Validation\Violation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StructureValidator::class)]
#[UsesClass(ValidationResult::class)]
#[UsesClass(Violation::class)]
#[UsesClass(TagNesting::class)]
#[UsesClass(TranslationUnit::class)]
#[UsesClass(UnitId::class)]
#[UsesClass(InlineTag::class)]
#[UsesClass(UnitText::class)]
#[UsesClass(UnitTextToken::class)]
#[UsesClass(UntrustedText::class)]
final class StructureValidatorTest extends TestCase
{
    /** Tags 1–3 paired, 4 void, 5 opaque (see block()). */
    private const string SOURCE = 'Click <g1>here</g1> to <g2>go <g3>on</g3></g2><x4/><x5/>';

    /**
     * @return iterable<string, array{string}>
     */
    public static function validTranslations(): iterable
    {
        yield 'identical' => [self::SOURCE];
        yield 'translated text' => ['Kliknij <g1>tutaj</g1>, aby <g2>iść <g3>dalej</g3></g2><x4/><x5/>'];
        yield 'reordered siblings' => ['<x5/><g2>dalej <g3>iść</g3></g2> <g1>tutaj</g1> kliknij<x4/>'];
        yield 'nested tag moved out of its parent' => ['<g3>dalej</g3> <g1>tutaj</g1> <g2>iść</g2> <x4/><x5/>'];
        yield 'whitespace before /> and >' => ['<g1 >tutaj</g1 > <g2>iść <g3>dalej</g3></g2><x4 /><x5/>'];
        yield 'literal less-than and entities' => ['a < b &nbsp;&amp; c & d <g1>x</g1><g2><g3>y</g3></g2><x4/><x5/>'];
    }

    #[Test]
    #[DataProvider('validTranslations')]
    public function it_accepts_valid_translations(string $translated): void
    {
        $result = new StructureValidator()->validate($this->block(self::SOURCE), $translated);

        self::assertTrue($result->isValid(), $result->describe());
        self::assertSame([], $result->violations());
    }

    /**
     * @return iterable<string, array{string, list<array{string, string}>}>
     */
    public static function violations(): iterable
    {
        yield 'tags without text' => ['<g1></g1><g2><g3></g3></g2><x4/><x5/>', [['EmptyTranslation', 'Translate the text; the translation must not be empty.']]];
        yield 'raw markup' => [
            '<b>Kliknij</b> <g1>tutaj</g1> <g2>iść <g3>dalej</g3></g2><x4/><x5/>',
            [
                ['RawMarkup', 'Remove the markup "<b>": use only the tags from the source text, and write a literal "<" as &lt;.'],
                ['RawMarkup', 'Remove the markup "</b>": use only the tags from the source text, and write a literal "<" as &lt;.'],
            ],
        ];
        yield 'long raw markup is shortened' => [
            '<g1>a</g1> <g2>b <g3>c</g3></g2><x4/><x5/> <span class="a-very-long-class-name">',
            [['RawMarkup', 'Remove the markup "<span class="a-very-long-clas…": use only the tags from the source text, and write a literal "<" as &lt;.']],
        ];
        yield 'unknown paired tag' => [
            '<g1>a</g1> <g2>b <g3>c</g3></g2><x4/><x5/> <g6>d</g6>',
            [['UnknownTag', 'Remove <g6>: there is no tag 6 in the source text.']],
        ];
        yield 'unknown void tag' => [
            '<g1>a</g1> <g2>b <g3>c</g3></g2><x4/><x5/><x9/>',
            [['UnknownTag', 'Remove <x9/>: there is no tag 9 in the source text.']],
        ];
        yield 'unknown closing tag only' => [
            '<g1>a</g1> <g2>b <g3>c</g3></g2><x4/><x5/></g7>',
            [['UnknownTag', 'Remove </g7>: there is no tag 7 in the source text.']],
        ];
        yield 'void tag written as paired' => [
            '<g1>a</g1> <g2>b <g3>c</g3></g2><g4>x</g4><x5/>',
            [['WrongTagForm', 'Write tag 4 as <x4/>, not as <g4>…</g4>.']],
        ];
        yield 'opaque tag written as paired' => [
            '<g1>a</g1> <g2>b <g3>c</g3></g2><x4/><g5></g5>',
            [['WrongTagForm', 'Write tag 5 as <x5/>, not as <g5>…</g5>.']],
        ];
        yield 'paired tag written as void' => [
            '<x1/> <g2>b <g3>c</g3></g2><x4/><x5/>',
            [['WrongTagForm', 'Write tag 1 as <g1>…</g1>, not as <x1/>.']],
        ];
        yield 'missing paired tag' => [
            'a <g2>b <g3>c</g3></g2><x4/><x5/>',
            [['MissingTag', 'Add <g1>…</g1>: every tag from the source text must appear exactly once.']],
        ];
        yield 'missing void tag' => [
            '<g1>a</g1> <g2>b <g3>c</g3></g2><x5/>',
            [['MissingTag', 'Add <x4/>: every tag from the source text must appear exactly once.']],
        ];
        yield 'duplicate void tag' => [
            '<g1>a</g1> <g2>b <g3>c</g3></g2><x4/><x4/><x5/>',
            [['DuplicateTag', 'Use <x4/> only once; it appears 2 times.']],
        ];
        yield 'duplicate paired tag' => [
            '<g1>a</g1> <g1>b</g1> <g2>c <g3>d</g3></g2><x4/><x5/>',
            [['DuplicateTag', 'Use <g1>…</g1> only once; it appears 2 times.']],
        ];
        yield 'unclosed tag' => [
            '<g1>a <g2>b <g3>c</g3></g2><x4/><x5/>',
            [['UnclosedTag', 'Close <g1> with </g1>.']],
        ];
        yield 'closing tag without opening' => [
            'a</g1> <g2>b <g3>c</g3></g2><x4/><x5/>',
            [['UnexpectedClosingTag', 'Remove </g1>: it has no matching <g1>.']],
        ];
        yield 'overlapping tags' => [
            '<g1>a <g2>b</g1> <g3>c</g3></g2><x4/><x5/>',
            [['ImproperNesting', 'Close <g2> before </g1>: tags must not overlap.']],
        ];
        yield 'deeply overlapping tags' => [
            '<g1>a <g2>b <g3>c</g1> d</g2> e</g3><x4/><x5/>',
            [['ImproperNesting', 'Close <g3> and <g2> before </g1>: tags must not overlap.']],
        ];
        yield 'repeated closing tag without opening is reported once' => [
            'a</g1></g1> <g2>b <g3>c</g3></g2><x4/><x5/>',
            [['UnexpectedClosingTag', 'Remove </g1>: it has no matching <g1>.']],
        ];
        yield 'repeated unclosed tag is reported once' => [
            '<g1>a<g1>b <g2>c <g3>d</g3></g2><x4/><x5/>',
            [
                ['UnclosedTag', 'Close <g1> with </g1>.'],
                ['DuplicateTag', 'Use <g1>…</g1> only once; it appears 2 times.'],
            ],
        ];
        yield 'wrong form repeated is reported once' => [
            '<g1>a</g1> <g2>b <g3>c</g3></g2><g4>x</g4><g4>y</g4><x5/>',
            [['WrongTagForm', 'Write tag 4 as <x4/>, not as <g4>…</g4>.']],
        ];
        yield 'raw markup of exactly 30 characters is quoted whole' => [
            '<g1>a</g1> <g2>b <g3>c</g3></g2><x4/><x5/> <s title="日本語日本語日本語日本語日本語日本語">',
            [['RawMarkup', 'Remove the markup "<s title="日本語日本語日本語日本語日本語日本語">": use only the tags from the source text, and write a literal "<" as &lt;.']],
        ];
        yield 'different unknown tags are each reported' => [
            '<g1>a</g1> <g2>b <g3>c</g3></g2><x4/><x5/><x6/><x7/>',
            [
                ['UnknownTag', 'Remove <x6/>: there is no tag 6 in the source text.'],
                ['UnknownTag', 'Remove <x7/>: there is no tag 7 in the source text.'],
            ],
        ];
        yield 'one tag with two different problems' => [
            'a</g1> <g1>b <g2>c <g3>d</g3></g2><x4/><x5/>',
            [
                ['UnexpectedClosingTag', 'Remove </g1>: it has no matching <g1>.'],
                ['UnclosedTag', 'Close <g1> with </g1>.'],
            ],
        ];
        yield 'raw markup in a wide script is shortened by characters' => [
            '<g1>a</g1> <g2>b <g3>c</g3></g2><x4/><x5/> <span title="日本語日本語日本語日本語日本語日本語">',
            [['RawMarkup', 'Remove the markup "<span title="日本語日本語日本語日本語日本語日…": use only the tags from the source text, and write a literal "<" as &lt;.']],
        ];

        // Combinations keep a fixed order: empty, problems in text order, unclosed, duplicates, missing.
        yield 'raw markup instead of tags' => [
            'Kliknij <b>tutaj</b> <g2>iść <g3>dalej</g3></g2><x5/>',
            [
                ['RawMarkup', 'Remove the markup "<b>": use only the tags from the source text, and write a literal "<" as &lt;.'],
                ['RawMarkup', 'Remove the markup "</b>": use only the tags from the source text, and write a literal "<" as &lt;.'],
                ['MissingTag', 'Add <g1>…</g1>: every tag from the source text must appear exactly once.'],
                ['MissingTag', 'Add <x4/>: every tag from the source text must appear exactly once.'],
            ],
        ];
        yield 'unclosed, duplicate and missing' => [
            '<g2>b <g3>c</g3> <g2>d</g2><x4/><x4/><x5/>',
            [
                ['UnclosedTag', 'Close <g2> with </g2>.'],
                ['DuplicateTag', 'Use <g2>…</g2> only once; it appears 2 times.'],
                ['DuplicateTag', 'Use <x4/> only once; it appears 2 times.'],
                ['MissingTag', 'Add <g1>…</g1>: every tag from the source text must appear exactly once.'],
            ],
        ];
        yield 'duplicates by ascending number, not by text order' => [
            '<x4/><x4/> <g2>b <g3>c</g3></g2> <g2>d</g2> <g1>a</g1><x5/>',
            [
                ['DuplicateTag', 'Use <g2>…</g2> only once; it appears 2 times.'],
                ['DuplicateTag', 'Use <x4/> only once; it appears 2 times.'],
            ],
        ];
        yield 'unknown tag before wrong form' => [
            '<g8>a</g8> <g1>a</g1> <g2>b <g3>c</g3></g2><g4>d</g4><x5/>',
            [
                ['UnknownTag', 'Remove <g8>: there is no tag 8 in the source text.'],
                ['WrongTagForm', 'Write tag 4 as <x4/>, not as <g4>…</g4>.'],
            ],
        ];
        yield 'empty with raw markup' => [
            '<b></b>',
            [
                ['EmptyTranslation', 'Translate the text; the translation must not be empty.'],
                ['RawMarkup', 'Remove the markup "<b>": use only the tags from the source text, and write a literal "<" as &lt;.'],
                ['RawMarkup', 'Remove the markup "</b>": use only the tags from the source text, and write a literal "<" as &lt;.'],
                ['MissingTag', 'Add <g1>…</g1>: every tag from the source text must appear exactly once.'],
                ['MissingTag', 'Add <g2>…</g2>: every tag from the source text must appear exactly once.'],
                ['MissingTag', 'Add <g3>…</g3>: every tag from the source text must appear exactly once.'],
                ['MissingTag', 'Add <x4/>: every tag from the source text must appear exactly once.'],
                ['MissingTag', 'Add <x5/>: every tag from the source text must appear exactly once.'],
            ],
        ];
    }

    /**
     * @param list<array{string, string}> $expected
     */
    #[Test]
    #[DataProvider('violations')]
    public function it_reports_violations(string $translated, array $expected): void
    {
        $result = new StructureValidator()->validate($this->block(self::SOURCE), $translated);

        self::assertFalse($result->isValid());
        self::assertSame($expected, $this->describe($result));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyTranslations(): iterable
    {
        yield 'empty string' => [''];
        yield 'whitespace' => [" \n\t"];
        yield 'no-break and zero-width spaces' => ["\u{00A0}\u{200B}"];
        yield 'encoded no-break space' => ['&nbsp;'];
    }

    #[Test]
    #[DataProvider('emptyTranslations')]
    public function it_rejects_an_empty_translation(string $translated): void
    {
        self::assertSame([['EmptyTranslation', 'Translate the text; the translation must not be empty.']], $this->describe(new StructureValidator()->validate($this->block('Hello'), $translated)));
    }

    #[Test]
    public function it_reports_missing_tags_by_ascending_number_whatever_the_source_order(): void
    {
        $source = TranslationUnit::fromText(UnitId::fromString('u1'), UnitKind::Block, '<x2/> <g1>a</g1>', [
            InlineTag::of(2, InlineTagKind::Void),
            InlineTag::of(1, InlineTagKind::Paired),
        ]);

        self::assertSame(
            [
                ['MissingTag', 'Add <g1>…</g1>: every tag from the source text must appear exactly once.'],
                ['MissingTag', 'Add <x2/>: every tag from the source text must appear exactly once.'],
            ],
            $this->describe(new StructureValidator()->validate($source, 'a')),
        );
    }

    #[Test]
    public function it_reports_invalid_utf8_alone(): void
    {
        $result = new StructureValidator()->validate($this->block(self::SOURCE), "Zaż\xFFółć <g1>a</g1>");

        self::assertSame([['InvalidEncoding', 'Rewrite the translation as valid UTF-8 text.']], $this->describe($result));
    }

    #[Test]
    public function it_accepts_a_unit_without_tags(): void
    {
        self::assertTrue(new StructureValidator()->validate($this->block('Hello'), 'Cześć')->isValid());
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
    public function it_accepts_plain_text_units(UnitKind $kind): void
    {
        $source = TranslationUnit::fromText(UnitId::fromString('a1'), $kind, 'Tom &amp; Jerry');

        self::assertTrue(new StructureValidator()->validate($source, 'Tom i Jerry & spółka')->isValid());
    }

    #[Test]
    #[DataProvider('plainTextKinds')]
    public function it_rejects_tags_in_plain_text_units_once(UnitKind $kind): void
    {
        $source = TranslationUnit::fromText(UnitId::fromString('a1'), $kind, 'Company logo');

        $result = new StructureValidator()->validate($source, 'Logo <g1>firmy</g1><x2/> <b>x</b>');

        self::assertSame(
            [
                ['TagsInPlainText', 'Remove all tags such as <g1>, </g1> and <x1/>: this text is plain text.'],
                ['RawMarkup', 'Remove the markup "<b>": use only the tags from the source text, and write a literal "<" as &lt;.'],
                ['RawMarkup', 'Remove the markup "</b>": use only the tags from the source text, and write a literal "<" as &lt;.'],
            ],
            $this->describe($result),
        );
    }

    #[Test]
    #[DataProvider('plainTextKinds')]
    public function it_rejects_empty_plain_text_units(UnitKind $kind): void
    {
        $source = TranslationUnit::fromText(UnitId::fromString('a1'), $kind, 'Company logo');

        self::assertSame([['EmptyTranslation', 'Translate the text; the translation must not be empty.']], $this->describe(new StructureValidator()->validate($source, " \u{00A0}")));
    }

    /**
     * The tags of SOURCE: 1–3 paired, 4 void, 5 opaque; no tags for any other text.
     */
    private function block(string $text): TranslationUnit
    {
        $tags = self::SOURCE === $text ? [
            InlineTag::of(1, InlineTagKind::Paired),
            InlineTag::of(2, InlineTagKind::Paired),
            InlineTag::of(3, InlineTagKind::Paired),
            InlineTag::of(4, InlineTagKind::Void),
            InlineTag::of(5, InlineTagKind::Opaque),
        ] : [];

        return TranslationUnit::fromText(UnitId::fromString('u1'), UnitKind::Block, $text, $tags);
    }

    /**
     * @return list<array{string, string}>
     */
    private function describe(ValidationResult $result): array
    {
        return array_map(static fn(Violation $violation): array => [$violation->type->name, $violation->message], $result->violations());
    }
}

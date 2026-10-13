<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Domain;

use HtmlTranslator\Domain\UnitText;
use HtmlTranslator\Domain\UnitTextToken;
use HtmlTranslator\Domain\UnitTextTokenKind;
use HtmlTranslator\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnitText::class)]
#[CoversClass(UnitTextToken::class)]
#[UsesClass(InvalidArgumentException::class)]
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

    /**
     * @return iterable<string, array{string, list<array{string, int|null, string}>}>
     */
    public static function tokens(): iterable
    {
        yield 'empty' => ['', []];
        yield 'text only' => ['Hello', [['Text', null, 'Hello']]];
        yield 'paired tag' => ['a <g1>b</g1> c', [['Text', null, 'a '], ['Open', 1, '<g1>'], ['Text', null, 'b'], ['Close', 1, '</g1>'], ['Text', null, ' c']]];
        yield 'void tag' => ['x<x2/>y', [['Text', null, 'x'], ['Void', 2, '<x2/>'], ['Text', null, 'y']]];
        yield 'multi-digit number' => ['<g12></g12>', [['Open', 12, '<g12>'], ['Close', 12, '</g12>']]];
        yield 'adjacent tags' => ['<g1><x2/></g1>', [['Open', 1, '<g1>'], ['Void', 2, '<x2/>'], ['Close', 1, '</g1>']]];

        // ADR-0009: whitespace right before > and /> is tolerated.
        yield 'space before />' => ['<x1 />', [['Void', 1, '<x1 />']]];
        yield 'tab before />' => ["<x1\t/>", [['Void', 1, "<x1\t/>"]]];
        yield 'space before > in opening tag' => ['<g1 >a</g1 >', [['Open', 1, '<g1 >'], ['Text', null, 'a'], ['Close', 1, '</g1 >']]];

        // ADR-0009: all HTML5 character references are decoded, in one pass.
        yield 'the three escapes' => ['Tom &amp; Jerry &lt;3 &gt;', [['Text', null, 'Tom & Jerry <3 >']]];
        yield 'other references' => ['&nbsp;&#169;&quot;&#x41;', [['Text', null, "\u{00A0}©\"A"]]];
        yield 'double-escaped reference stays literal' => ['&amp;nbsp;', [['Text', null, '&nbsp;']]];
        yield 'unknown reference and bare ampersand' => ['&foo; & a', [['Text', null, '&foo; & a']]];
        yield 'decoded text next to tags' => ['&lt;<g1>&amp;</g1>', [['Text', null, '<'], ['Open', 1, '<g1>'], ['Text', null, '&'], ['Close', 1, '</g1>']]];

        // ADR-0009: a < is markup only before a letter, "/", "!" or "?".
        yield 'less-than before a space' => ['a < b', [['Text', null, 'a < b']]];
        yield 'less-than before a digit' => ['5<6 and <3', [['Text', null, '5<6 and <3']]];
        yield 'less-than at the end' => ['a <', [['Text', null, 'a <']]];
        yield 'space after less-than is not a tag' => ['< g1>', [['Text', null, '< g1>']]];
        yield 'html element' => ['<b>x</b>', [['RawMarkup', null, '<b>'], ['Text', null, 'x'], ['RawMarkup', null, '</b>']]];
        yield 'html element with attributes' => ['<a href="/x">link</a>', [['RawMarkup', null, '<a href="/x">'], ['Text', null, 'link'], ['RawMarkup', null, '</a>']]];
        yield 'comment' => ['a<!-- c -->b', [['Text', null, 'a'], ['RawMarkup', null, '<!-- c -->'], ['Text', null, 'b']]];
        yield 'processing instruction' => ['<?php x ?>', [['RawMarkup', null, '<?php x ?>']]];
        yield 'unterminated markup runs to the end' => ['a <b and more', [['Text', null, 'a '], ['RawMarkup', null, '<b and more']]];
        yield 'number zero' => ['<g0>', [['RawMarkup', null, '<g0>']]];
        yield 'leading zero' => ['<g01>', [['RawMarkup', null, '<g01>']]];
        yield 'upper-case letter' => ['<G1>', [['RawMarkup', null, '<G1>']]];
        yield 'closing void' => ['</x1>', [['RawMarkup', null, '</x1>']]];
        yield 'void without slash' => ['<x1>', [['RawMarkup', null, '<x1>']]];
        yield 'self-closing paired' => ['<g1/>', [['RawMarkup', null, '<g1/>']]];
        yield 'unterminated pseudo-tag' => ['a<g1', [['Text', null, 'a'], ['RawMarkup', null, '<g1']]];
        yield 'number with too many digits is raw markup' => ['<g1234567>', [['RawMarkup', null, '<g1234567>']]];
        yield 'six-digit number' => ['<x123456/>', [['Void', 123456, '<x123456/>']]];
        yield 'newline before >' => ["<g1\n>a</g1>", [['Open', 1, "<g1\n>"], ['Text', null, 'a'], ['Close', 1, '</g1>']]];
        yield 'reference split by a tag stays literal' => ['&am<g1>p;</g1>', [['Text', null, '&am'], ['Open', 1, '<g1>'], ['Text', null, 'p;'], ['Close', 1, '</g1>']]];
        yield 'pseudo-tag swallowed by raw markup' => ['<b <g1>x</g1>', [['RawMarkup', null, '<b <g1>'], ['Text', null, 'x'], ['Close', 1, '</g1>']]];
        yield 'greater-than inside a comment ends the raw markup' => ['<!-- a > b -->', [['RawMarkup', null, '<!-- a >'], ['Text', null, ' b -->']]];
        yield 'references html_entity_decode keeps literal' => ['&#0;&#xD800;&nbsp', [['Text', null, '&#0;&#xD800;&nbsp']]];
        yield 'multibyte text' => ['Zażółć <g1>日本</g1> 😀', [['Text', null, 'Zażółć '], ['Open', 1, '<g1>'], ['Text', null, '日本'], ['Close', 1, '</g1>'], ['Text', null, ' 😀']]];
    }

    /**
     * @param list<array{string, int|null, string}> $expected
     */
    #[Test]
    #[DataProvider('tokens')]
    public function it_tokenizes_model_output(string $text, array $expected): void
    {
        self::assertSame(
            $expected,
            array_map(static fn(UnitTextToken $token): array => [$token->kind->name, $token->number, $token->text], UnitText::tokenize($text)),
        );
    }

    #[Test]
    public function it_round_trips_escaped_text(): void
    {
        $plain = 'Tom & Jerry <3 "quoted" > &nbsp;';

        $tokens = UnitText::tokenize(UnitText::escape($plain));

        self::assertCount(1, $tokens);
        self::assertSame($plain, $tokens[0]->text);
    }

    /**
     * PCRE's start-of-match optimisation made every preg_match at an offset scan ahead for ">".
     */
    #[Test]
    public function it_tokenizes_in_linear_time(): void
    {
        $text = str_repeat('a < b <1 ', 50_000);

        $start = hrtime(true);
        $tokens = UnitText::tokenize($text);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        self::assertCount(1, $tokens);
        self::assertLessThan(500, $elapsedMs, 'Tokenizing must stay linear.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function plainTexts(): iterable
    {
        yield 'text only' => ['Hello', 'Hello'];
        yield 'escapes decoded' => ['Tom &amp; Jerry &lt;3', 'Tom & Jerry <3'];
        yield 'paired tag becomes spaces' => ['pull <g1>request</g1>', 'pull  request '];
        yield 'tag never joins words' => ['Git<g1>Hub</g1>', 'Git Hub '];
        yield 'void tag' => ['Press<x1/>Enter', 'Press Enter'];
        yield 'raw markup' => ['a <b>c', 'a  c'];
    }

    #[Test]
    #[DataProvider('plainTexts')]
    public function it_gives_the_plain_text_a_reader_sees(string $text, string $expected): void
    {
        self::assertSame($expected, UnitText::plainText($text));
    }

    /**
     * @return iterable<string, array{UnitTextTokenKind}>
     */
    public static function tagKinds(): iterable
    {
        yield 'open' => [UnitTextTokenKind::Open];
        yield 'close' => [UnitTextTokenKind::Close];
        yield 'void' => [UnitTextTokenKind::Void];
    }

    #[Test]
    #[DataProvider('tagKinds')]
    public function its_tag_tokens_need_a_positive_number(UnitTextTokenKind $kind): void
    {
        $this->expectException(InvalidArgumentException::class);

        match ($kind) {
            UnitTextTokenKind::Open => UnitTextToken::open(0, '<g0>'),
            UnitTextTokenKind::Close => UnitTextToken::close(0, '</g0>'),
            default => UnitTextToken::void(0, '<x0/>'),
        };
    }

    #[Test]
    public function its_tag_tokens_carry_kind_and_number(): void
    {
        self::assertSame(
            [['Open', 1, '<g1>'], ['Close', 2, '</g2>'], ['Void', 3, '<x3/>']],
            array_map(
                static fn(UnitTextToken $token): array => [$token->kind->name, $token->number, $token->text],
                [UnitTextToken::open(1, '<g1>'), UnitTextToken::close(2, '</g2>'), UnitTextToken::void(3, '<x3/>')],
            ),
        );
    }
}

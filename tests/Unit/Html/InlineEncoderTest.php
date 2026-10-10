<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Html;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use HtmlTranslator\Domain\InlineTag;
use HtmlTranslator\Domain\UnitText;
use HtmlTranslator\Html\EncodedRun;
use HtmlTranslator\Html\InlineEncoder;
use HtmlTranslator\Html\SkipRules;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InlineEncoder::class)]
#[CoversClass(EncodedRun::class)]
#[UsesClass(InlineTag::class)]
#[UsesClass(UnitText::class)]
#[UsesClass(SkipRules::class)]
final class InlineEncoderTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, list<array{int, string}>}>
     */
    public static function runs(): iterable
    {
        yield 'text only' => ['Hello world', 'Hello world', []];
        yield 'paired' => ['Click <a href="/x">here</a>', 'Click <g1>here</g1>', [[1, 'Paired']]];
        yield 'nested paired in opening order' => ['<a href="/"><b>x</b> <i>y</i></a>', '<g1><g2>x</g2> <g3>y</g3></g1>', [[1, 'Paired'], [2, 'Paired'], [3, 'Paired']]];
        yield 'void elements' => ['a<br>b<img src="i.png"><wbr><input name="n">', 'a<x1/>b<x2/><x3/><x4/>', [[1, 'Void'], [2, 'Void'], [3, 'Void'], [4, 'Void']]];
        yield 'empty non-void element is paired' => ['a<span></span>b', 'a<g1></g1>b', [[1, 'Paired']]];
        yield 'skipped element is opaque' => ['Run <code>x <b>y</b></code> now', 'Run <x1/> now', [[1, 'Opaque']]];
        yield 'translate=no is opaque' => ['A <span translate="no">B <i>C</i></span> D', 'A <x1/> D', [[1, 'Opaque']]];
        yield 'comment is opaque' => ['a <!-- c --> b', 'a <x1/> b', [[1, 'Opaque']]];
        yield 'numbering continues after opaque' => ['<b>a</b><code>b</code><br><i>c</i>', '<g1>a</g1><x2/><x3/><g4>c</g4>', [[1, 'Paired'], [2, 'Opaque'], [3, 'Void'], [4, 'Paired']]];
        yield 'text is escaped' => ['a &lt; b &amp; c &gt; d', 'a &lt; b &amp; c &gt; d', []];
        yield 'escaped inside tags' => ['<b>&lt;g1&gt;</b>', '<g1>&lt;g1&gt;</g1>', [[1, 'Paired']]];
        yield 'multibyte text' => ['Zażółć <b>日本</b> 😀', 'Zażółć <g1>日本</g1> 😀', [[1, 'Paired']]];
    }

    /**
     * @param list<array{int, string}> $tags
     */
    #[Test]
    #[DataProvider('runs')]
    public function it_encodes_a_run(string $html, string $text, array $tags): void
    {
        $encoded = new InlineEncoder()->encode($this->nodesOf($html), SkipRules::defaults());

        self::assertSame($text, $encoded->text);
        self::assertSame($tags, array_map(static fn(InlineTag $tag): array => [$tag->number, $tag->kind->name], $encoded->inlineTags));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function translatableText(): iterable
    {
        yield 'text' => ['a <b>b</b>', true];
        yield 'text inside a tag only' => ['<b>b</b>', true];
        yield 'whitespace and void' => [" <br>\n", false];
        yield 'only opaque content' => ['<code>c</code> <span translate="no">n</span>', false];
        yield 'no-break space' => ['&nbsp;', false];
        yield 'text before trailing whitespace' => ['a<br> ', true];
    }

    #[Test]
    #[DataProvider('translatableText')]
    public function it_reports_whether_the_run_holds_translatable_text(string $html, bool $expected): void
    {
        self::assertSame($expected, new InlineEncoder()->encode($this->nodesOf($html), SkipRules::defaults())->hasTranslatableText);
    }

    #[Test]
    public function it_restarts_numbering_for_every_run(): void
    {
        $encoder = new InlineEncoder();

        self::assertSame('<g1>a</g1>', $encoder->encode($this->nodesOf('<b>a</b>'), SkipRules::defaults())->text);
        self::assertSame('<g1>b</g1>', $encoder->encode($this->nodesOf('<i>b</i>'), SkipRules::defaults())->text);
    }

    #[Test]
    public function it_maps_numbers_to_the_encoded_nodes(): void
    {
        $encoded = new InlineEncoder()->encode($this->nodesOf('<a href="/x"><b>x</b></a><br><code>c</code><!-- n -->'), SkipRules::defaults());

        self::assertSame([1, 2, 3, 4, 5], array_keys($encoded->tagNodes));
        self::assertSame(
            ['a', 'b', 'br', 'code', '#comment'],
            array_map(static fn(Node $node): string => strtolower($node->nodeName), array_values($encoded->tagNodes)),
        );
    }

    #[Test]
    public function it_lists_translatable_elements_for_attribute_units(): void
    {
        $encoded = new InlineEncoder()->encode(
            $this->nodesOf('<a id="1" href="/"><img id="2" alt="x"></a><code id="3"><b id="4">x</b></code><span id="5" translate="no">y</span><i id="6">z</i>'),
            SkipRules::defaults(),
        );

        self::assertSame(['1', '2', '6'], array_map(static fn(Element $element): ?string => $element->getAttribute('id'), $encoded->attributeCandidates));
    }

    #[Test]
    public function it_uses_the_given_skip_rules(): void
    {
        $encoded = new InlineEncoder()->encode($this->nodesOf('<code>x</code> <x-brand>Acme</x-brand>'), SkipRules::defaults()->withoutElement('code')->withElement('x-brand'));

        self::assertSame('<g1>x</g1> <x2/>', $encoded->text);
    }

    /**
     * @return list<Node>
     */
    private function nodesOf(string $html): array
    {
        $document = HTMLDocument::createFromString('<!DOCTYPE html><body><p>' . $html . '</p></body>', \LIBXML_NOERROR, 'UTF-8');
        $paragraph = $document->querySelector('p') ?? self::fail('No paragraph.');

        return array_values(iterator_to_array($paragraph->childNodes));
    }
}

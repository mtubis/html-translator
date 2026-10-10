<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Html;

use Dom\Comment;
use Dom\Element;
use Dom\Node;
use Dom\Text;
use HtmlTranslator\Domain\InlineTag;
use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Domain\UnitId;
use HtmlTranslator\Domain\UnitText;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Exception\InvalidHtmlException;
use HtmlTranslator\Html\AttributeAnchor;
use HtmlTranslator\Html\EncodedRun;
use HtmlTranslator\Html\HtmlLoader;
use HtmlTranslator\Html\InlineEncoder;
use HtmlTranslator\Html\LoadedDocument;
use HtmlTranslator\Html\RunAnchor;
use HtmlTranslator\Html\SegmentationContext;
use HtmlTranslator\Html\SegmentedDocument;
use HtmlTranslator\Html\Segmenter;
use HtmlTranslator\Html\SkipRules;
use HtmlTranslator\Html\TranslatableAttributes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Segmenter::class)]
#[CoversClass(SegmentedDocument::class)]
#[CoversClass(RunAnchor::class)]
#[CoversClass(AttributeAnchor::class)]
#[CoversClass(SegmentationContext::class)]
#[UsesClass(InlineEncoder::class)]
#[UsesClass(EncodedRun::class)]
#[UsesClass(HtmlLoader::class)]
#[UsesClass(LoadedDocument::class)]
#[UsesClass(SkipRules::class)]
#[UsesClass(TranslatableAttributes::class)]
#[UsesClass(TranslationUnit::class)]
#[UsesClass(UnitId::class)]
#[UsesClass(UnitText::class)]
#[UsesClass(InlineTag::class)]
#[UsesClass(InvalidArgumentException::class)]
#[UsesClass(InvalidHtmlException::class)]
final class SegmenterTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<array{string, string, string}>}>
     */
    public static function blockUnits(): iterable
    {
        yield 'issue example' => ['<p>Click <a href="/x"><b>here</b></a> now<br>ok</p>', [['u1', 'Block', 'Click <g1><g2>here</g2></g1> now<x3/>ok']]];
        yield 'plain text fragment' => ['Just text', [['u1', 'Block', 'Just text']]];
        yield 'two paragraphs' => ["<p>One</p>\n  <p>Two</p>", [['u1', 'Block', 'One'], ['u2', 'Block', 'Two']]];
        yield 'headings and inline markup' => ['<h1>A</h1><h2>B <small>c</small></h2>', [['u1', 'Block', 'A'], ['u2', 'Block', 'B <g1>c</g1>']]];
        yield 'multibyte text' => ['<p>Zażółć <b>gęślą</b> jaźń 😀</p>', [['u1', 'Block', 'Zażółć <g1>gęślą</g1> jaźń 😀']]];

        // Nested blocks: units only at the innermost text-bearing level.
        yield 'list item with paragraph' => ['<ul><li><p>Text</p></li></ul>', [['u1', 'Block', 'Text']]];
        yield 'blockquote with paragraphs' => ['<blockquote><p>A</p><p>B</p></blockquote>', [['u1', 'Block', 'A'], ['u2', 'Block', 'B']]];
        yield 'table cell with paragraph' => ['<table><tr><td><p>Cell</p></td><td>Plain</td></tr></table>', [['u1', 'Block', 'Cell'], ['u2', 'Block', 'Plain']]];
        yield 'table parts' => [
            '<table><caption>Cap</caption><thead><tr><th>H</th></tr></thead><tbody><tr><td>D</td></tr></tbody></table>',
            [['u1', 'Block', 'Cap'], ['u2', 'Block', 'H'], ['u3', 'Block', 'D']],
        ];
        yield 'definition list' => ['<dl><dt>Term</dt><dd>Def</dd></dl>', [['u1', 'Block', 'Term'], ['u2', 'Block', 'Def']]];
        yield 'details and summary' => ['<details><summary>More</summary>Hidden text</details>', [['u1', 'Block', 'More'], ['u2', 'Block', 'Hidden text']]];

        // Mixed content: a block splits the surrounding inline run.
        yield 'text next to paragraph in div' => ['<div>Intro <p>Para</p> outro</div>', [['u1', 'Block', 'Intro'], ['u2', 'Block', 'Para'], ['u3', 'Block', 'outro']]];
        yield 'text next to block in table cell' => ['<table><tr><td>Total <p>Note</p></td></tr></table>', [['u1', 'Block', 'Total'], ['u2', 'Block', 'Note']]];
        yield 'text next to nested list' => ['<ul><li>Item <ul><li>Sub</li></ul></li></ul>', [['u1', 'Block', 'Item'], ['u2', 'Block', 'Sub']]];
        yield 'text next to button in div' => ['<div>Text <button>OK</button></div>', [['u1', 'Block', 'Text <g1>OK</g1>']]];
        yield 'text next to label in div' => ['<div>Choose: <label><input type="checkbox"> Yes</label></div>', [['u1', 'Block', 'Choose: <g1><x2/> Yes</g1>']]];
        yield 'text next to figcaption' => ['<figure>Figure 1 <figcaption>Caption</figcaption></figure>', [['u1', 'Block', 'Figure 1'], ['u2', 'Block', 'Caption']]];
        yield 'hr splits a run' => ['<div>A<hr>B</div>', [['u1', 'Block', 'A'], ['u2', 'Block', 'B']]];
        yield 'block inside a button' => ['<button>Save <div>x</div></button>', [['u1', 'Block', 'Save'], ['u2', 'Block', 'x']]];
        yield 'block inside a label' => ['<label>Name <p>hint</p></label>', [['u1', 'Block', 'Name'], ['u2', 'Block', 'hint']]];
        yield 'block inside a figcaption' => ['<figure><figcaption>Cap <p>note</p></figcaption></figure>', [['u1', 'Block', 'Cap'], ['u2', 'Block', 'note']]];
        yield 'text, block and tail in a list item' => ['<ul><li>Text <p>Para</p> tail</li></ul>', [['u1', 'Block', 'Text'], ['u2', 'Block', 'Para'], ['u3', 'Block', 'tail']]];

        // A single wrapping inline element is unwrapped.
        yield 'link alone in list item' => ['<ul><li><a href="/x">Link</a></li></ul>', [['u1', 'Block', 'Link']]];
        yield 'button alone in div' => ['<div><button>OK</button></div>', [['u1', 'Block', 'OK']]];
        yield 'strong alone in paragraph' => ['<p><strong>All bold</strong></p>', [['u1', 'Block', 'All bold']]];
        yield 'nested wrappers' => ['<p><a href="/"><b>Deep</b></a></p>', [['u1', 'Block', 'Deep']]];
        yield 'wrapper with surrounding whitespace' => ["<p>\n <b>Bold</b> \n</p>", [['u1', 'Block', 'Bold']]];
        yield 'label alone in form' => ['<form><label>Name <input name="n"></label></form>', [['u1', 'Block', 'Name <x1/>']]];
        yield 'no unwrap with text next to it' => ['<ul><li><a href="/x">Link</a> more</li></ul>', [['u1', 'Block', '<g1>Link</g1> more']]];

        // Void and opaque inline nodes.
        yield 'void elements' => ['<p>a<br>b<wbr>c <img src="i.png"> d</p>', [['u1', 'Block', 'a<x1/>b<x2/>c <x3/> d']]];
        yield 'leading void element' => ['<p><br>Text</p>', [['u1', 'Block', '<x1/>Text']]];
        yield 'inline code is opaque' => ['<p>Run <code>composer install</code> now</p>', [['u1', 'Block', 'Run <x1/> now']]];
        yield 'inline svg is opaque' => ['<p>Icon <svg><circle r="1"></circle></svg> text</p>', [['u1', 'Block', 'Icon <x1/> text']]];
        yield 'inline script is opaque' => ['<p>Hi<script>var x = 1;</script> there</p>', [['u1', 'Block', 'Hi<x1/> there']]];
        yield 'inline translate=no is opaque' => ['<p>Use <span translate="no">Acme Corp</span> today</p>', [['u1', 'Block', 'Use <x1/> today']]];
        yield 'inline notranslate is opaque' => ['<p>Use <span class="notranslate">Acme</span>.</p>', [['u1', 'Block', 'Use <x1/>.']]];
        yield 'translate=yes inside an opaque inline is ignored' => ['<p>Use <span translate="no">Acme <b translate="yes">Corp</b></span></p>', [['u1', 'Block', 'Use <x1/>']]];
        yield 'translate=yes inside a lone translate=no wrapper is honoured' => ['<p><span translate="no">Acme <b translate="yes">Corp</b></span></p>', [['u1', 'Block', 'Corp']]];
        yield 'comment inside a sentence is opaque' => ['<p>a <!-- c --> b</p>', [['u1', 'Block', 'a <x1/> b']]];
        yield 'comments at run edges are left out' => ["<ul><li>\n  <!-- x -->\n  Text\n  <!-- y -->\n</li></ul>", [['u1', 'Block', 'Text']]];
        yield 'numbering mixes kinds in opening order' => ['<p><b>a<br>b</b><code>c</code><i>d</i></p>', [['u1', 'Block', '<g1>a<x2/>b</g1><x3/><g4>d</g4>']]];

        // Nothing to translate.
        yield 'whitespace-only paragraph' => ["<p>  \n </p>", []];
        yield 'empty paragraph' => ['<p></p>', []];
        yield 'no-break space only' => ['<p>&nbsp; &nbsp;</p>', []];
        yield 'zero-width space only' => ['<p>&#8203;</p>', []];
        yield 'em space only' => ['<p>&#8195;</p>', []];
        yield 'only inline code' => ['<p><code>x</code></p>', []];
        yield 'only void element' => ['<p><br></p>', []];

        // translate attribute and skip classes on blocks.
        yield 'block translate=no' => ['<p translate="no">Brand</p><p>Text</p>', [['u1', 'Block', 'Text']]];
        yield 'block under notranslate' => ['<div class="notranslate"><p>A</p></div><p>B</p>', [['u1', 'Block', 'B']]];
        yield 'block translate=yes under no' => ['<div translate="no"><p>A</p><p translate="yes">B</p></div>', [['u1', 'Block', 'B']]];
        yield 'inline translate=yes under a block with no' => ['<p translate="no">Brand <span translate="yes">tagline</span></p>', [['u1', 'Block', 'tagline']]];

        // Skipped subtrees.
        yield 'skipped elements next to a paragraph' => [
            '<div><script>x</script><style>p{}</style><pre>code</pre><template><p>T</p></template><p>Text</p></div>',
            [['u1', 'Block', 'Text']],
        ];
        yield 'block-level svg and math' => ['<div><svg><text>s</text></svg><math><mi>m</mi></math></div><p>Text</p>', [['u1', 'Block', 'Text']]];

        // Structure.
        yield 'block nested two inline levels deep' => ['<div>a <span><b><p>x</p></b></span></div>', [['u1', 'Block', 'a'], ['u2', 'Block', 'x']]];
        yield 'title element alone in body content' => ['<div><title>x</title></div>', [['u1', 'Block', 'x']]];
        yield 'inline element containing blocks acts as a container' => ['<a href="/"><div>Card title</div><p>Desc</p></a>', [['u1', 'Block', 'Card title'], ['u2', 'Block', 'Desc']]];
        yield 'custom element is inline' => ['<p>Hi <my-widget>there</my-widget></p>', [['u1', 'Block', 'Hi <g1>there</g1>']]];
        yield 'custom element containing a block' => ['<my-card><p>Body</p></my-card>', [['u1', 'Block', 'Body']]];
        yield 'select with options in a label' => [
            '<label>Pick <select><option>One</option><option>Two</option></select></label>',
            [['u1', 'Block', 'Pick'], ['u2', 'Block', 'One'], ['u3', 'Block', 'Two']],
        ];
        yield 'title element in body content is inline' => ['<p>a <title>x</title></p>', [['u1', 'Block', 'a <g1>x</g1>']]];
        yield 'ruby annotation' => ['<p><ruby>漢字<rt>かんじ</rt></ruby>です</p>', [['u1', 'Block', '<g1>漢字<g2>かんじ</g2></g1>です']]];

        // ADR-0008: unit text escapes &, < and >.
        yield 'escaped special characters' => ['<p>a &lt; b &amp;&amp; c &gt; d</p>', [['u1', 'Block', 'a &lt; b &amp;&amp; c &gt; d']]];
        yield 'literal pseudo-tag in source text' => ['<p>Type &lt;g1&gt; or &lt;x2/&gt; here</p>', [['u1', 'Block', 'Type &lt;g1&gt; or &lt;x2/&gt; here']]];
    }

    /**
     * @param list<array{string, string, string}> $expected
     */
    #[Test]
    #[DataProvider('blockUnits')]
    public function it_segments_block_units(string $html, array $expected): void
    {
        self::assertSame($expected, $this->triples($this->segment($html)));
    }

    /**
     * @return iterable<string, array{string, list<array{string, string, string}>}>
     */
    public static function attributeAndTitleUnits(): iterable
    {
        yield 'attribute on a block comes before its content' => ['<p title="Tip">Text</p>', [['a1', 'Attribute', 'Tip'], ['u1', 'Block', 'Text']]];
        yield 'attribute on an inline element follows the run' => [
            '<p>Go <a href="/x" title="Home page">home</a> now</p>',
            [['u1', 'Block', 'Go <g1>home</g1> now'], ['a1', 'Attribute', 'Home page']],
        ];
        yield 'attribute on an unwrapped wrapper' => ['<ul><li><a href="/x" title="Tip">Link</a></li></ul>', [['a1', 'Attribute', 'Tip'], ['u1', 'Block', 'Link']]];
        yield 'several attributes in document order' => ['<p><img src="a.png" alt="Logo" title="Company"></p>', [['a1', 'Attribute', 'Logo'], ['a2', 'Attribute', 'Company']]];
        yield 'image next to a caption' => [
            '<figure><img src="a.png" alt="Chart"><figcaption>Sales <b>2026</b></figcaption></figure>',
            [['a1', 'Attribute', 'Chart'], ['u1', 'Block', 'Sales <g1>2026</g1>']],
        ];
        yield 'attributes of an inline skipped element' => ['<p>See <code title="T">x</code></p>', [['u1', 'Block', 'See <x1/>'], ['a1', 'Attribute', 'T']]];
        yield 'no attribute units inside an inline skipped element' => ['<p>See <code><b title="T">x</b></code></p>', [['u1', 'Block', 'See <x1/>']]];
        yield 'no attribute units on an inline translate=no element' => ['<p>See <span translate="no" title="T">x</span></p>', [['u1', 'Block', 'See <x1/>']]];
        yield 'attributes of a skipped element, not of its descendants' => ['<pre title="T">x <b title="B">y</b></pre><svg aria-label="Close"><a title="S"></a></svg>', [['a1', 'Attribute', 'T'], ['a2', 'Attribute', 'Close']]];
        yield 'skipped element with translate=yes below no' => ['<div translate="no"><svg translate="yes" aria-label="A"></svg></div>', [['a1', 'Attribute', 'A']]];
        yield 'head metadata titles are not translated' => [
            '<!DOCTYPE html><html><head><title>Page</title><style title="Dark">p{}</style><link rel="alternate stylesheet" title="Dark" href="d.css">'
            . '<script title="S">x</script><template title="Tpl"><p>t</p></template><meta name="description" content="About"></head><body></body></html>',
            [['u1', 'Title', 'Page'], ['a1', 'Attribute', 'About']],
        ];
        yield 'no attribute units on a skipped element with translate=no' => ['<code translate="no" title="T">x</code>', []];
        yield 'no attribute units on translate=no elements' => ['<img src="a.png" alt="Logo" translate="no">', []];
        yield 'attribute with translate=yes under no' => ['<div translate="no"><img src="a.png" alt="A" translate="yes"></div>', [['a1', 'Attribute', 'A']]];
        yield 'attribute text is escaped' => ['<img src="a.png" alt="Tom &amp; Jerry <3">', [['a1', 'Attribute', 'Tom &amp; Jerry &lt;3']]];
        yield 'full document with head' => [
            '<!DOCTYPE html><html><head><title>Page &amp; title</title><meta name="description" content="About"><meta name="viewport" content="width=device-width"></head>'
            . '<body><h1>Hello</h1><input type="submit" value="Send"></body></html>',
            [['u1', 'Title', 'Page &amp; title'], ['a1', 'Attribute', 'About'], ['u2', 'Block', 'Hello'], ['a2', 'Attribute', 'Send']],
        ];
        yield 'title with translate=no' => ['<!DOCTYPE html><html><head><title translate="no">Brand</title></head><body><p>x</p></body></html>', [['u1', 'Block', 'x']]];
        yield 'blank title' => ['<!DOCTYPE html><html><head><title> </title></head><body><p>x</p></body></html>', [['u1', 'Block', 'x']]];
    }

    /**
     * @param list<array{string, string, string}> $expected
     */
    #[Test]
    #[DataProvider('attributeAndTitleUnits')]
    public function it_segments_attribute_and_title_units(string $html, array $expected): void
    {
        self::assertSame($expected, $this->triples($this->segment($html)));
    }

    /**
     * Interface text of forms is translated, form data (field values, submitted button values) is not.
     *
     * @return iterable<string, array{string, list<array{string, string, string}>}>
     */
    public static function formControls(): iterable
    {
        yield 'textarea content is data' => ['<div><textarea name="m">Default text</textarea></div>', []];
        yield 'textarea in a sentence is opaque' => ['<p>Write <textarea name="m">Default</textarea> here</p>', [['u1', 'Block', 'Write <x1/> here']]];
        yield 'textarea placeholder and title are interface' => [
            '<textarea name="m" placeholder="Your message" title="Message">Default</textarea>',
            [['a1', 'Attribute', 'Your message'], ['a2', 'Attribute', 'Message']],
        ];
        yield 'textarea with placeholder in a sentence' => ['<p>Type <textarea name="m" placeholder="P">D</textarea> here</p>', [['u1', 'Block', 'Type <x1/> here'], ['a1', 'Attribute', 'P']]];
        yield 'button content is interface, its value is data' => ['<button name="action" value="delete">Delete</button>', [['u1', 'Block', 'Delete']]];
        yield 'full form' => [
            '<form><fieldset><legend>Account</legend>'
            . '<label for="e">E-mail</label> <input id="e" name="email" type="email" placeholder="you@example.com" value="kept@example.com">'
            . '<select name="c" aria-label="Country"><option value="pl">Poland</option></select>'
            . '<input type="text" name="q" value="kept query">'
            . '<input type="submit" name="action" value="Save">'
            . '<input type="submit" value="Send">'
            . '<input type="reset" value="Clear">'
            . '</fieldset></form>',
            [
                ['u1', 'Block', 'Account'],
                ['u2', 'Block', '<g1>E-mail</g1> <x2/>'],
                ['a1', 'Attribute', 'you@example.com'],
                ['a2', 'Attribute', 'Country'],
                ['u3', 'Block', 'Poland'],
                ['a3', 'Attribute', 'Send'],
                ['a4', 'Attribute', 'Clear'],
            ],
        ];
    }

    /**
     * @param list<array{string, string, string}> $expected
     */
    #[Test]
    #[DataProvider('formControls')]
    public function it_translates_form_interface_but_not_form_data(string $html, array $expected): void
    {
        self::assertSame($expected, $this->triples($this->segment($html)));
    }

    #[Test]
    public function it_translates_textarea_content_when_opted_in(): void
    {
        $segmented = $this->segment('<div><textarea name="m">Default text</textarea></div>', SkipRules::defaults()->withoutElement('textarea'));

        self::assertSame([['u1', 'Block', 'Default text']], $this->triples($segmented));
    }

    #[Test]
    public function it_uses_the_given_rules(): void
    {
        $rules = SkipRules::defaults()->withoutElement('code')->withElement('x-brand');
        $attributes = TranslatableAttributes::defaults()->withoutAttribute('title')->withAttribute('data-tooltip');

        $segmented = $this->segment('<p title="T" data-tooltip="Tip">Run <code>it</code> with <x-brand>Acme</x-brand></p>', $rules, $attributes);

        self::assertSame([['a1', 'Attribute', 'Tip'], ['u1', 'Block', 'Run <g1>it</g1> with <x2/>']], $this->triples($segmented));
    }

    #[Test]
    public function it_keeps_surrounding_whitespace_out_of_the_text(): void
    {
        // Whitespace-only edge nodes stay in the DOM (see the run anchor tests); whitespace inside an edge text node is split off.
        $unit = $this->segment("<p>\n  Hello <b>world</b>!\n</p>")->units()[0] ?? self::fail('No unit.');

        self::assertSame("\n  ", $unit->leadingWhitespace);
        self::assertSame('Hello <g1>world</g1>!', $unit->text);
        self::assertSame("\n", $unit->trailingWhitespace);
    }

    #[Test]
    public function it_keeps_inner_whitespace_unchanged(): void
    {
        self::assertSame([['u1', 'Block', "a\n   b"]], $this->triples($this->segment("<p>a\n   b</p>")));
    }

    #[Test]
    public function it_records_inline_tags_on_the_unit(): void
    {
        $unit = $this->segment('<p>a <b>b</b><br><code>c</code></p>')->units()[0] ?? self::fail('No unit.');

        self::assertSame(
            [[1, 'Paired'], [2, 'Void'], [3, 'Opaque']],
            array_map(static fn(InlineTag $tag): array => [$tag->number, $tag->kind->name], $unit->inlineTags),
        );
    }

    /**
     * Markup that carries the marker ATTRVAL only in untranslatable attributes, and SKIPPED only in skipped content.
     *
     * @return iterable<string, array{string}>
     */
    public static function guaranteeCorpus(): iterable
    {
        yield 'links and classes' => ['<p class="ATTRVAL" id="ATTRVAL">Go <a href="/ATTRVAL" data-x="ATTRVAL" style="color:red">home</a> <img src="ATTRVAL.png" alt="Logo"></p>'];
        yield 'skipped inline' => ['<p>Run <code>SKIPPED</code>, <kbd>SKIPPED</kbd>, <samp>SKIPPED</samp> and <var>SKIPPED</var>.</p>'];
        yield 'skipped blocks' => ['<pre>SKIPPED</pre><script>SKIPPED</script><style>SKIPPED{}</style><template><p>SKIPPED</p></template><p>Text</p>'];
        yield 'foreign content' => ['<p>Icon <svg><text>SKIPPED</text><title>SKIPPED</title></svg> and <math><mi>SKIPPED</mi></math></p>'];
        yield 'translate=no' => ['<p translate="no">SKIPPED</p><p>Use <span translate="no">SKIPPED <b>SKIPPED</b></span> and <span class="notranslate">SKIPPED</span></p>'];
        yield 'skipped attributes' => ['<p translate="no" title="SKIPPED"><img alt="SKIPPED"></p><code><b title="SKIPPED">x</b></code><p>Text</p>'];
        yield 'comments' => ['<p>a <!-- SKIPPED --> b</p><!-- SKIPPED -->'];
    }

    #[Test]
    #[DataProvider('guaranteeCorpus')]
    public function it_never_puts_attribute_values_or_skipped_text_into_units(string $html): void
    {
        foreach ($this->segment($html)->units() as $unit) {
            self::assertStringNotContainsString('ATTRVAL', $unit->text, \sprintf('Unit %s leaks an attribute value.', $unit->id));
            self::assertStringNotContainsString('SKIPPED', $unit->text, \sprintf('Unit %s leaks skipped content.', $unit->id));
        }
    }

    #[Test]
    #[DataProvider('guaranteeCorpus')]
    public function it_gives_unique_ids_and_numbers_tags_from_one_per_unit(string $html): void
    {
        $ids = [];
        foreach ($this->segment($html)->units() as $unit) {
            $ids[] = $unit->id->toString();
            self::assertSame(
                [] === $unit->inlineTags ? [] : range(1, \count($unit->inlineTags)),
                array_map(static fn(InlineTag $tag): int => $tag->number, $unit->inlineTags),
            );
        }

        self::assertSame(array_values(array_unique($ids)), $ids);
    }

    /**
     * The Reassembler replaces every run's nodes, so runs must neither share nor contain each other's nodes.
     */
    #[Test]
    #[DataProvider('guaranteeCorpus')]
    #[DataProvider('anchorCorpus')]
    public function its_run_anchors_are_disjoint_runs_of_consecutive_siblings(string $html): void
    {
        $segmented = $this->segment($html);
        $runs = [];
        foreach ($segmented->units() as $unit) {
            $anchor = $segmented->anchorFor($unit->id);
            if ($anchor instanceof RunAnchor) {
                $runs[] = $anchor->nodes;
            }
        }

        self::assertNotSame([], $runs);

        foreach ($runs as $i => $nodes) {
            foreach (\array_slice($nodes, 1) as $position => $node) {
                self::assertSame($nodes[$position] ?? null, $node->previousSibling, 'Run nodes must be consecutive siblings.');
            }

            foreach ($runs as $j => $others) {
                if ($i === $j) {
                    continue;
                }

                foreach ($nodes as $node) {
                    foreach ($others as $other) {
                        self::assertFalse($node->contains($other), 'Runs must not overlap.');
                    }
                }
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function anchorCorpus(): iterable
    {
        yield 'mixed content' => ['<div>Intro <p>Para <b>bold</b></p> outro <ul><li>Item <a href="/">link</a> <ul><li>Sub</li></ul></li></ul></div>'];
        yield 'unwrapped wrappers' => ['<ul><li><a href="/"><b>Deep</b></a></li><li><button>OK</button></li></ul>'];
        yield 're-enabled translation' => ['<div translate="no"><p translate="yes">A <i>b</i></p><p>no <span translate="yes">yes</span></p></div>'];
        yield 'containers inside inline elements' => ['<a href="/"><div>Card</div>tail <p>Desc</p></a><label>Pick <select><option>One</option></select></label>'];
    }

    /**
     * Attribute units of inline elements point at the node that the run's pseudo-tag also refers to;
     * the Reassembler must therefore apply attribute units before it replaces runs with clones.
     */
    #[Test]
    public function its_attribute_anchor_of_an_inline_element_is_the_tag_node_of_the_run(): void
    {
        $segmented = $this->segment('<p>go <a href="/" title="home page">here</a></p>');
        $run = $segmented->anchorFor(UnitId::fromString('u1'));
        $attribute = $segmented->anchorFor(UnitId::fromString('a1'));

        self::assertInstanceOf(RunAnchor::class, $run);
        self::assertInstanceOf(AttributeAnchor::class, $attribute);
        self::assertSame($run->tags[1] ?? null, $attribute->element);
    }

    #[Test]
    public function it_segments_deeply_nested_markup_in_linear_time(): void
    {
        $depth = 1000;
        $wrapped = '<div>' . str_repeat('<span>', $depth) . 'x' . str_repeat('</span>', $depth) . '</div>';
        $inSentence = '<p>a ' . str_repeat('<b>', $depth) . 'x' . str_repeat('</b>', $depth) . '</p>';

        $start = hrtime(true);
        $units = [...$this->segment($wrapped)->units(), ...$this->segment($inSentence)->units()];
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        self::assertCount(2, $units);
        self::assertLessThan(1000, $elapsedMs, 'Segmentation of deep nesting must stay linear.');
    }

    #[Test]
    public function it_accepts_markup_nested_exactly_to_the_limit(): void
    {
        // The fragment container is level 0, so 1024 nested spans reach level 1024.
        self::assertCount(1, $this->segment(str_repeat('<span>', 1024) . 'x')->units());
    }

    #[Test]
    public function it_rejects_markup_nested_deeper_than_the_limit(): void
    {
        $this->expectException(InvalidHtmlException::class);
        $this->expectExceptionMessage('1024');

        $this->segment(str_repeat('<span>', 1025) . 'x');
    }

    #[Test]
    public function it_maps_inline_tags_to_the_original_nodes(): void
    {
        $segmented = $this->segment('<p>Click <a href="/x"><b>here</b></a> now<br><code>c</code><!-- note -->ok</p>');
        $anchor = $segmented->anchorFor(UnitId::fromString('u1'));

        self::assertInstanceOf(RunAnchor::class, $anchor);
        self::assertSame([1, 2, 3, 4, 5], array_keys($anchor->tags));

        $link = $anchor->tags[1] ?? null;
        self::assertInstanceOf(Element::class, $link);
        self::assertSame('a', $link->localName);
        self::assertSame('/x', $link->getAttribute('href'));
        self::assertSame('b', $this->localName($anchor->tags[2] ?? null));
        self::assertSame('br', $this->localName($anchor->tags[3] ?? null));

        $code = $anchor->tags[4] ?? null;
        self::assertInstanceOf(Element::class, $code);
        self::assertSame('code', $code->localName);
        self::assertSame('c', $code->textContent);

        $comment = $anchor->tags[5] ?? null;
        self::assertInstanceOf(Comment::class, $comment);
        self::assertSame(' note ', $comment->data);
    }

    #[Test]
    public function its_run_anchor_lists_the_sibling_nodes_to_replace(): void
    {
        $segmented = $this->segment('<p>Click <a href="/x">here</a> now<br>ok</p>');
        $anchor = $segmented->anchorFor(UnitId::fromString('u1'));
        self::assertInstanceOf(RunAnchor::class, $anchor);

        $paragraph = $segmented->document()->root()->firstElementChild ?? self::fail('No paragraph.');
        self::assertSame(iterator_to_array($paragraph->childNodes), $anchor->nodes);
    }

    #[Test]
    public function its_run_anchor_leaves_trimmed_edges_out(): void
    {
        $segmented = $this->segment("<ul><li>\n<!-- x -->\nText\n<!-- y --></li></ul>");
        $anchor = $segmented->anchorFor(UnitId::fromString('u1'));
        self::assertInstanceOf(RunAnchor::class, $anchor);

        self::assertCount(1, $anchor->nodes);
        $text = $anchor->nodes[0];
        self::assertInstanceOf(Text::class, $text);
        self::assertSame("\nText\n", $text->data);
        self::assertSame('li', $text->parentElement?->localName);
        self::assertSame(4, $text->parentElement->childNodes->length, 'Comments and whitespace stay in the DOM.');
    }

    #[Test]
    public function its_run_anchor_points_inside_an_unwrapped_wrapper(): void
    {
        $anchor = $this->segment('<ul><li><a href="/x">Link</a></li></ul>')->anchorFor(UnitId::fromString('u1'));
        self::assertInstanceOf(RunAnchor::class, $anchor);

        self::assertCount(1, $anchor->nodes);
        self::assertSame('a', $anchor->nodes[0]->parentElement?->localName);
        self::assertSame([], $anchor->tags);
    }

    #[Test]
    public function its_title_anchor_is_a_run_of_the_title_text(): void
    {
        $anchor = $this->segment('<!DOCTYPE html><html><head><title>Page</title></head><body></body></html>')->anchorFor(UnitId::fromString('u1'));
        self::assertInstanceOf(RunAnchor::class, $anchor);

        self::assertCount(1, $anchor->nodes);
        self::assertSame('title', $anchor->nodes[0]->parentElement?->localName);
    }

    #[Test]
    public function it_maps_attribute_units_to_element_and_attribute(): void
    {
        $anchor = $this->segment('<p>See <img src="a.png" alt="Logo"></p>')->anchorFor(UnitId::fromString('a1'));

        self::assertInstanceOf(AttributeAnchor::class, $anchor);
        self::assertSame('img', $anchor->element->localName);
        self::assertSame('alt', $anchor->attribute);
    }

    #[Test]
    public function it_keeps_the_loaded_document(): void
    {
        $document = new HtmlLoader()->load('<p>x</p>');

        self::assertSame($document, new Segmenter()->segment($document, SkipRules::defaults(), TranslatableAttributes::defaults())->document());
    }

    #[Test]
    public function it_rejects_unknown_unit_ids(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"u9"');

        $this->segment('<p>x</p>')->anchorFor(UnitId::fromString('u9'));
    }

    private function segment(string $html, ?SkipRules $rules = null, ?TranslatableAttributes $attributes = null): SegmentedDocument
    {
        return new Segmenter()->segment(
            new HtmlLoader()->load($html),
            $rules ?? SkipRules::defaults(),
            $attributes ?? TranslatableAttributes::defaults(),
        );
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function triples(SegmentedDocument $segmented): array
    {
        return array_map(
            static fn(TranslationUnit $unit): array => [$unit->id->toString(), $unit->kind->name, $unit->text],
            $segmented->units(),
        );
    }

    private function localName(?Node $node): string
    {
        self::assertInstanceOf(Element::class, $node);

        return $node->localName;
    }
}

<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Html;

use HtmlTranslator\Domain\InlineTag;
use HtmlTranslator\Domain\InlineTagKind;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Domain\UnitId;
use HtmlTranslator\Domain\UnitKind;
use HtmlTranslator\Domain\UnitText;
use HtmlTranslator\Domain\UnitTextToken;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Exception\InvalidHtmlException;
use HtmlTranslator\Html\AttributeAnchor;
use HtmlTranslator\Html\EncodedRun;
use HtmlTranslator\Html\HtmlLoader;
use HtmlTranslator\Html\InlineEncoder;
use HtmlTranslator\Html\LoadedDocument;
use HtmlTranslator\Html\Reassembler;
use HtmlTranslator\Html\RunAnchor;
use HtmlTranslator\Html\SegmentationContext;
use HtmlTranslator\Html\SegmentedDocument;
use HtmlTranslator\Html\Segmenter;
use HtmlTranslator\Html\SkipRules;
use HtmlTranslator\Html\TranslatableAttributes;
use HtmlTranslator\Tests\Support\DomAssertions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Reassembler::class)]
#[UsesClass(Segmenter::class)]
#[UsesClass(SegmentationContext::class)]
#[UsesClass(SegmentedDocument::class)]
#[UsesClass(RunAnchor::class)]
#[UsesClass(AttributeAnchor::class)]
#[UsesClass(InlineEncoder::class)]
#[UsesClass(EncodedRun::class)]
#[UsesClass(HtmlLoader::class)]
#[UsesClass(LoadedDocument::class)]
#[UsesClass(SkipRules::class)]
#[UsesClass(TranslatableAttributes::class)]
#[UsesClass(TranslationUnit::class)]
#[UsesClass(UnitId::class)]
#[UsesClass(InlineTag::class)]
#[UsesClass(UnitText::class)]
#[UsesClass(UnitTextToken::class)]
#[UsesClass(Locale::class)]
#[UsesClass(InvalidArgumentException::class)]
#[UsesClass(InvalidHtmlException::class)]
final class ReassemblerTest extends TestCase
{
    use DomAssertions;

    private const string FIXTURES = __DIR__ . '/../../Fixtures/roundtrip';

    /**
     * @return iterable<string, array{string}>
     */
    public static function fixtures(): iterable
    {
        $files = glob(self::FIXTURES . '/*.html');
        self::assertNotFalse($files);

        foreach ($files as $file) {
            yield basename($file, '.html') => [$file];
        }
    }

    /**
     * Identity translations must give back the input; the target is the document's own language.
     */
    #[Test]
    #[DataProvider('fixtures')]
    public function it_round_trips_every_fixture_with_identity_translations(string $file): void
    {
        $html = file_get_contents($file);
        self::assertIsString($html);
        $target = 1 === preg_match('/<html[^>]*\slang="([^"]+)"/i', $html, $match) ? $match[1] : 'en';

        $segmented = $this->segment($html);
        new Reassembler()->apply($segmented, $this->identity($segmented), Locale::fromString($target));

        if ($segmented->document()->isFragment()) {
            self::assertSameFragmentStructure($html, $segmented->document()->serialize());
        } else {
            self::assertSameDocumentStructure($html, $segmented->document()->serialize());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function segmentationCorpus(): iterable
    {
        yield 'mixed content' => ['<div>Intro <p>Para <b>bold</b></p> outro <ul><li>Item <a href="/">link</a> <ul><li>Sub</li></ul></li></ul></div>'];
        yield 'unwrapped wrappers and edges' => ["<ul>\n<li>\n<!-- x -->\n<a href=\"/\"><b>Deep</b></a>\n</li><li><button>OK</button></li></ul>"];
        yield 'opaque content' => ['<p>Run <code class="c">x <b>y</b></code>, <span translate="no">Acme</span> and <svg><circle r="1"></circle></svg> <!-- c --> now</p>'];
        yield 'attributes everywhere' => ['<p title="T">Go <a href="/" title="Home">home</a> <img src="a.png" alt="A"> <code title="C">c</code></p>'];
        yield 'forms' => ['<form><label>Name <input name="n" placeholder="P"></label><select><option>Red</option><option value="g">Green</option></select><textarea placeholder="M">data</textarea><input type="submit" value="Send"></form>'];
        yield 're-enabled translation' => ['<div translate="no"><p translate="yes">A <i>b</i></p><p>no <span translate="yes">yes</span></p></div>'];
        yield 'padded attribute values' => ['<img src="a.png" alt=" Logo  " title="\tT\n">'];
        yield 'inline template' => ['<p>a <template><b>t</b><template><i>n</i></template></template> b</p>'];
        yield 'template inside an opaque element' => ['<p>Use <span translate="no">x <template><b>t</b></template></span> now</p>'];
        yield 'raw-text elements' => ['<p>a <iframe>f</iframe> <xmp>x</xmp> <noembed>e</noembed> <noframes>n</noframes> b</p><div><iframe>alone</iframe></div>'];
        yield 'escaped text' => ['<p>a &lt; b &amp;&amp; c &gt; d &nbsp;&lt;g1&gt;</p>'];
    }

    #[Test]
    #[DataProvider('segmentationCorpus')]
    public function it_round_trips_segmentation_cases_with_identity_translations(string $html): void
    {
        $segmented = $this->segment($html);
        new Reassembler()->apply($segmented, $this->identity($segmented), Locale::fromString('en'));

        self::assertSameFragmentStructure($html, $segmented->document()->serialize());
    }

    /**
     * @return iterable<string, array{string, array<string, string>, string}>
     */
    public static function translations(): iterable
    {
        yield 'issue example' => [
            '<p>Click <a href="/x"><b>here</b></a> now<br>ok</p>',
            ['u1' => 'Kliknij <g1><g2>tutaj</g2></g1> teraz<x3/>ok'],
            '<p>Kliknij <a href="/x"><b>tutaj</b></a> teraz<br>ok</p>',
        ];
        yield 'reordered tags give reordered elements' => [
            '<p><b>a</b> and <i>b</i></p>',
            ['u1' => '<g2>B</g2> i <g1>A</g1>'],
            '<p><i>B</i> i <b>A</b></p>',
        ];
        yield 'nested tag moved out of its parent' => [
            '<p>a <a href="/"><b>x</b> y</a></p>',
            ['u1' => '<g2>X</g2> a <g1>Y</g1>'],
            '<p><b>X</b> a <a href="/">Y</a></p>',
        ];
        yield 'text between nested closing tags' => [
            '<p>a <a href="/"><b>x</b> y</a></p>',
            ['u1' => 'A <g1><g2>X</g2> Y</g1> Z'],
            '<p>A <a href="/"><b>X</b> Y</a> Z</p>',
        ];
        yield 'opaque element keeps its subtree' => [
            '<p>Run <code class="c">composer <b>install</b></code> now</p>',
            ['u1' => 'Uruchom <x1/> teraz'],
            '<p>Uruchom <code class="c">composer <b>install</b></code> teraz</p>',
        ];
        yield 'opaque comment is kept' => ['<p>a <!-- note --> b</p>', ['u1' => 'A <x1/> B'], '<p>A <!-- note --> B</p>'];
        yield 'void element is kept' => ['<p>a<br>b <img src="i.png" alt=""></p>', ['u1' => 'A<x1/>B <x2/>'], '<p>A<br>B <img src="i.png" alt=""></p>'];
        yield 'surrounding whitespace is restored' => ["<p>\n  Hello <b>world</b>!\n</p>", ['u1' => 'Cześć <g1>świecie</g1>!'], "<p>\n  Cześć <b>świecie</b>!\n</p>"];
        yield 'character references are decoded' => ['<p>Fish</p>', ['u1' => 'Ryby &amp; frytki&nbsp;&lt;3 &copy;'], "<p>Ryby &amp; frytki&nbsp;&lt;3 \u{00A9}</p>"];
        yield 'multibyte text' => ['<p>Hello <b>world</b></p>', ['u1' => 'こんにちは <g1>世界</g1> 😀'], '<p>こんにちは <b>世界</b> 😀</p>'];
        yield 'attributes' => ['<img src="a.png" alt="Logo" title="Company">', ['a1' => 'Logo firmy', 'a2' => 'Firma &amp; syn'], '<img src="a.png" alt="Logo firmy" title="Firma &amp; syn">'];
        yield 'attribute of an inline element ends up on its clone' => [
            '<p>go <a href="/" title="home page">here</a></p>',
            ['u1' => 'idź <g1>tutaj</g1>', 'a1' => 'strona główna'],
            '<p>idź <a href="/" title="strona główna">tutaj</a></p>',
        ];
        yield 'attribute of an opaque element ends up on its clone' => [
            '<p>See <code title="Runs it">x</code></p>',
            ['u1' => 'Zobacz <x1/>', 'a1' => 'Uruchamia'],
            '<p>Zobacz <code title="Uruchamia">x</code></p>',
        ];
        yield 'markup in an attribute translation stays text' => ['<img src="a.png" alt="Logo">', ['a1' => 'Logo <b>firmy</b> &amp; co'], '<img src="a.png" alt="Logo <b>firmy</b> &amp; co">'];
        yield 'units missing from the map stay untouched' => ['<p>One</p><p title="T">Two</p>', ['u2' => 'Dwa'], '<p>One</p><p title="T">Dwa</p>'];
        yield 'empty map changes nothing' => ['<p>One <b>two</b></p>', [], '<p>One <b>two</b></p>'];
    }

    /**
     * @param array<string, string> $translations
     */
    #[Test]
    #[DataProvider('translations')]
    public function it_applies_translations(string $html, array $translations, string $expected): void
    {
        self::assertSame($expected, $this->translate($html, $translations));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function scriptInjections(): iterable
    {
        yield 'escaped markup' => ['&lt;script&gt;alert(1)&lt;/script&gt;'];
        yield 'raw markup' => ['<script>alert(1)</script>'];
    }

    #[Test]
    #[DataProvider('scriptInjections')]
    public function it_inserts_markup_from_a_translation_as_text(string $translation): void
    {
        $segmented = $this->segment('<p>Hello</p>');
        new Reassembler()->apply($segmented, ['u1' => $translation], Locale::fromString('pl'));

        self::assertSame('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>', $segmented->document()->serialize());
        self::assertNull($segmented->document()->root()->querySelector('script'));
    }

    /**
     * @return iterable<string, array{string, SkipRules}>
     */
    public static function rawTextContexts(): iterable
    {
        foreach (['iframe', 'xmp', 'noembed', 'noframes'] as $name) {
            // A div, not a p: the parser closes a <p> before <xmp>.
            yield $name => [\sprintf('<div>a <%s>raw</%1$s> b</div>', $name), SkipRules::defaults()];
        }

        yield 'noscript (raw text in browsers with scripting)' => ['<div>a <noscript><img src="i.png" alt="x"></noscript> b</div>', SkipRules::defaults()];
        yield 'plaintext' => ['<div>a <plaintext>raw', SkipRules::defaults()];
        yield 'style inside svg lifted by configuration' => ['<div>a <svg><style>raw</style></svg> b</div>', SkipRules::defaults()->withoutElement('svg')];
        yield 'script lifted by configuration' => ['<p>a <script>raw</script> b</p>', SkipRules::defaults()->withoutElement('script')];
        yield 'style lifted by configuration' => ['<p>a <style>raw</style> b</p>', SkipRules::defaults()->withoutElement('style')];
    }

    /**
     * Text inside raw-text elements is serialized unescaped; such elements must never receive model text.
     */
    #[Test]
    #[DataProvider('rawTextContexts')]
    public function it_never_puts_model_text_into_raw_text_elements(string $html, SkipRules $rules): void
    {
        $segmented = new Segmenter()->segment(new HtmlLoader()->load($html), $rules, TranslatableAttributes::defaults());
        $unit = $segmented->units()[0] ?? self::fail('No unit.');
        $payload = '</iframe></xmp></noscript></style><img src=x onerror=alert(1)>';
        new Reassembler()->apply($segmented, ['u1' => $unit->text . ' ' . $payload], Locale::fromString('pl'));

        $reparsed = new HtmlLoader()->load($segmented->document()->serialize());
        self::assertNull($reparsed->root()->querySelector('[onerror]'));
    }

    #[Test]
    public function it_rejects_a_mapping_whose_paired_tag_is_a_raw_text_element(): void
    {
        $document = new HtmlLoader()->load('<p>a <iframe>raw</iframe></p>');
        $paragraph = $document->root()->firstElementChild ?? self::fail('No paragraph.');
        $iframe = $paragraph->lastElementChild ?? self::fail('No iframe.');
        $text = $paragraph->firstChild ?? self::fail('No text.');
        $unit = TranslationUnit::fromText(UnitId::fromString('u1'), UnitKind::Block, 'a <g1>raw</g1>', [InlineTag::of(1, InlineTagKind::Paired)]);
        $segmented = new SegmentedDocument($document, [$unit], ['u1' => new RunAnchor([$text, $iframe], [1 => $iframe])]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"u1"');

        new Reassembler()->apply($segmented, ['u1' => 'a <g1></iframe><img src=x onerror=alert(1)></g1>'], Locale::fromString('pl'));
    }

    #[Test]
    public function it_keeps_template_content_when_template_is_lifted_from_the_skip_rules(): void
    {
        $html = '<p>Hi <template><b>foo</b> bar</template> end</p>';
        $segmented = new Segmenter()->segment(new HtmlLoader()->load($html), SkipRules::defaults()->withoutElement('template'), TranslatableAttributes::defaults());
        new Reassembler()->apply($segmented, ['u1' => 'Hallo <x1/> Ende'], Locale::fromString('de'));

        self::assertSame('<p>Hallo <template><b>foo</b> bar</template> Ende</p>', $segmented->document()->serialize());
    }

    #[Test]
    public function it_rejects_a_run_inside_a_raw_text_element(): void
    {
        $document = new HtmlLoader()->load('<p>a <script>x</script></p>');
        $script = $document->root()->querySelector('script') ?? self::fail('No script.');
        $unit = TranslationUnit::fromText(UnitId::fromString('u1'), UnitKind::Block, 'x');
        $segmented = new SegmentedDocument($document, [$unit], ['u1' => new RunAnchor([...$script->childNodes], [])]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"u1"');

        new Reassembler()->apply($segmented, ['u1' => '</script><script>alert(1)</script>'], Locale::fromString('pl'));
    }

    /**
     * Hand-built anchors that the Segmenter never produces must still fail before anything is written.
     *
     * @return iterable<string, array{string}>
     */
    public static function inconsistentAnchors(): iterable
    {
        yield 'run nodes with different parents' => ['different parents'];
        yield 'node shared by two runs' => ['shared node'];
    }

    #[Test]
    #[DataProvider('inconsistentAnchors')]
    public function it_rejects_inconsistent_anchors_and_changes_nothing(string $case): void
    {
        $document = new HtmlLoader()->load('<p>One</p><p>Two</p><img src="a.png" alt="Logo">');
        $first = $document->root()->firstElementChild->firstChild ?? self::fail('No text.');
        $second = $document->root()->firstElementChild->nextElementSibling->firstChild ?? self::fail('No text.');
        $image = $document->root()->querySelector('img') ?? self::fail('No image.');

        $units = [
            TranslationUnit::fromText(UnitId::fromString('a1'), UnitKind::Attribute, 'Logo'),
            TranslationUnit::fromText(UnitId::fromString('u1'), UnitKind::Block, 'One'),
            TranslationUnit::fromText(UnitId::fromString('u2'), UnitKind::Block, 'Two'),
        ];
        $anchors = 'different parents' === $case
            ? ['a1' => new AttributeAnchor($image, 'alt'), 'u1' => new RunAnchor([$first, $second], []), 'u2' => new RunAnchor([$second], [])]
            : ['a1' => new AttributeAnchor($image, 'alt'), 'u1' => new RunAnchor([$first], []), 'u2' => new RunAnchor([$first], [])];
        $segmented = new SegmentedDocument($document, $units, $anchors);
        $before = $document->serialize();

        try {
            new Reassembler()->apply($segmented, ['a1' => 'Logo firmy', 'u1' => 'Jeden', 'u2' => 'Dwa'], Locale::fromString('pl'));
            self::fail('Expected exception was not thrown.');
        } catch (InvalidArgumentException) {
            self::assertSame($before, $document->serialize());
        }
    }

    #[Test]
    public function it_rejects_a_mapping_without_a_tag_of_the_unit(): void
    {
        $document = new HtmlLoader()->load('<p>A<br>B</p>');
        $paragraph = $document->root()->firstElementChild ?? self::fail('No paragraph.');
        $unit = TranslationUnit::fromText(UnitId::fromString('u1'), UnitKind::Block, 'A<x1/>B', [InlineTag::of(1, InlineTagKind::Void)]);
        $segmented = new SegmentedDocument($document, [$unit], ['u1' => new RunAnchor([...$paragraph->childNodes], [])]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"u1"');

        new Reassembler()->apply($segmented, ['u1' => 'A<x1/>B'], Locale::fromString('pl'));
    }

    #[Test]
    public function it_keeps_markup_in_a_title_translation_as_text(): void
    {
        $serialized = $this->translate('<!DOCTYPE html><html><head><title>Page</title></head><body></body></html>', ['u1' => '</title><script>alert(1)</script>']);

        $reparsed = new HtmlLoader()->load($serialized);
        self::assertNull($reparsed->root()->querySelector('script'));
        self::assertSame('</title><script>alert(1)</script>', $reparsed->root()->querySelector('title')?->textContent);
    }

    #[Test]
    public function it_builds_long_translations_in_linear_time(): void
    {
        $segmented = $this->segment('<p>Hello</p>');
        $translation = str_repeat('x<a>', 50_000);

        $start = hrtime(true);
        new Reassembler()->apply($segmented, ['u1' => $translation], Locale::fromString('pl'));
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        self::assertSame(200_000, \strlen($segmented->document()->root()->textContent ?? ''));
        self::assertLessThan(1000, $elapsedMs, 'Building the translated text must stay linear.');
    }

    /**
     * @return iterable<string, array{string, array<string, string>, string}>
     */
    public static function options(): iterable
    {
        yield 'option without value keeps its submitted value' => [
            '<select><option>Red</option><option value="g">Green</option><option>Blue</option></select>',
            ['u1' => 'Czerwony', 'u2' => 'Zielony'],
            '<select><option value="Red">Czerwony</option><option value="g">Zielony</option><option>Blue</option></select>',
        ];
        yield 'submitted value has collapsed whitespace' => [
            "<select><option>\n  Dark   red \n</option></select>",
            ['u1' => 'Ciemna czerwień'],
            "<select><option value=\"Dark red\">\n  Ciemna czerwień \n</option></select>",
        ];
        yield 'unchanged text needs no value' => [
            '<select><option>Red</option></select>',
            ['u1' => 'Red'],
            '<select><option>Red</option></select>',
        ];
        yield 'option inside an optgroup' => [
            '<select><optgroup label="Colours"><option>Red</option></optgroup></select>',
            ['u1' => 'Czerwony'],
            '<select><optgroup label="Colours"><option value="Red">Czerwony</option></optgroup></select>',
        ];
    }

    /**
     * @param array<string, string> $translations
     */
    #[Test]
    #[DataProvider('options')]
    public function it_preserves_what_options_submit(string $html, array $translations, string $expected): void
    {
        self::assertSame($expected, $this->translate($html, $translations));
    }

    #[Test]
    public function it_translates_the_title(): void
    {
        $serialized = $this->translate('<!DOCTYPE html><html lang="en"><head><title>Page</title></head><body><p>x</p></body></html>', ['u1' => 'Strona &amp; więcej']);

        self::assertStringContainsString('<title>Strona &amp; więcej</title>', $serialized);
    }

    /**
     * @return iterable<string, array{string, string, string, ?string}>
     */
    public static function documentLanguages(): iterable
    {
        yield 'replaces lang' => ['<html lang="en"><body><p>x</p></body></html>', 'pl', 'pl', null];
        yield 'adds lang' => ['<html><body><p>x</p></body></html>', 'de-CH', 'de-CH', null];
        yield 'sets dir for a right-to-left language' => ['<html lang="en"><body><p>x</p></body></html>', 'ar', 'ar', 'rtl'];
        yield 'sets dir for a right-to-left script' => ['<html lang="en"><body><p>x</p></body></html>', 'az-Arab', 'az-Arab', 'rtl'];
        yield 'turns an existing dir to ltr' => ['<html lang="ar" dir="rtl"><body><p>x</p></body></html>', 'en', 'en', 'ltr'];
        yield 'keeps rtl for another right-to-left language' => ['<html lang="ar" dir="rtl"><body><p>x</p></body></html>', 'he', 'he', 'rtl'];
    }

    #[Test]
    #[DataProvider('documentLanguages')]
    public function it_sets_the_document_language_and_direction(string $html, string $target, string $lang, ?string $dir): void
    {
        $segmented = $this->segment($html);
        new Reassembler()->apply($segmented, [], Locale::fromString($target));

        $root = $segmented->document()->root();
        self::assertSame($lang, $root->getAttribute('lang'));
        self::assertSame($dir, $root->getAttribute('dir'));
    }

    #[Test]
    public function it_does_not_add_language_attributes_to_fragments(): void
    {
        $segmented = $this->segment('<p>x</p>');
        new Reassembler()->apply($segmented, [], Locale::fromString('ar'));

        self::assertSame('<p>x</p>', $segmented->document()->serialize());
        self::assertFalse($segmented->document()->root()->hasAttribute('lang'));
        self::assertFalse($segmented->document()->root()->hasAttribute('dir'));
    }

    /**
     * apply() is meant to be called once with the complete map; a second call must fail before touching anything.
     *
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function secondApplications(): iterable
    {
        yield 'run applied again' => [['a2' => 'Tytuł', 'u1' => 'Hej'], '"u1" is no longer part of the document'];
        yield 'attribute of a replaced inline element' => [['a1' => 'Start'], '"a1" is no longer part of the document'];
    }

    /**
     * @param array<string, string> $translations
     */
    #[Test]
    #[DataProvider('secondApplications')]
    public function it_rejects_applying_to_replaced_nodes_and_changes_nothing(array $translations, string $message): void
    {
        $segmented = $this->segment('<p>Go <a href="/" title="Home">home</a></p><img src="a.png" alt="" title="Logo">');
        $reassembler = new Reassembler();
        $reassembler->apply($segmented, ['u1' => 'Idź <g1>do domu</g1>'], Locale::fromString('pl'));
        $before = $segmented->document()->serialize();

        try {
            $reassembler->apply($segmented, $translations, Locale::fromString('pl'));
            self::fail('Expected exception was not thrown.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }

        self::assertSame($before, $segmented->document()->serialize());
    }

    /**
     * @return iterable<string, array{string, array<string, string>, string}>
     */
    public static function invalidTranslations(): iterable
    {
        $html = '<p>Click <a href="/x"><b>here</b></a> now<br>ok</p><img src="a.png" alt="Logo">';

        yield 'unknown unit id' => [$html, ['u9' => 'x'], '"u9"'];
        yield 'missing tag' => [$html, ['u1' => 'Kliknij <g1>tutaj</g1> teraz<x3/>ok'], '"u1"'];
        yield 'unknown tag' => [$html, ['u1' => 'Kliknij <g1><g2>tutaj</g2></g1> teraz<x3/>ok<x4/>'], '"u1"'];
        yield 'unknown tag instead of a missing one' => [$html, ['u1' => 'Kliknij <g1><g2>tutaj</g2></g1> teraz<x4/>ok'], '"u1"'];
        yield 'duplicate tag' => [$html, ['u1' => 'Kliknij <g1><g2>tutaj</g2></g1> teraz<x3/>ok<x3/>'], '"u1"'];
        yield 'wrong tag form' => [$html, ['u1' => 'Kliknij <g1><g2>tutaj</g2></g1> teraz<g3>ok</g3>'], '"u1"'];
        yield 'unclosed tag' => [$html, ['u1' => 'Kliknij <g1><g2>tutaj</g2> teraz<x3/>ok'], '"u1"'];
        yield 'overlapping tags' => [$html, ['u1' => 'Kliknij <g1><g2>tutaj</g1></g2> teraz<x3/>ok'], '"u1"'];
        yield 'tag in an attribute unit' => [$html, ['a1' => 'Logo <g1>firmy</g1>'], '"a1"'];
    }

    /**
     * Everything is checked before the DOM is touched, so a bad translation leaves the document as it was.
     *
     * @param array<string, string> $translations
     */
    #[Test]
    #[DataProvider('invalidTranslations')]
    public function it_rejects_translations_that_do_not_match_the_source_and_changes_nothing(string $html, array $translations, string $message): void
    {
        $segmented = $this->segment($html);
        $before = $segmented->document()->serialize();

        try {
            new Reassembler()->apply($segmented, ['a1' => 'Logo firmy', ...$translations], Locale::fromString('pl'));
            self::fail('Expected exception was not thrown.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }

        self::assertSame($before, $segmented->document()->serialize());
    }

    private function segment(string $html): SegmentedDocument
    {
        return new Segmenter()->segment(new HtmlLoader()->load($html), SkipRules::defaults(), TranslatableAttributes::defaults());
    }

    /**
     * @return array<string, string>
     */
    private function identity(SegmentedDocument $segmented): array
    {
        $translations = [];
        foreach ($segmented->units() as $unit) {
            $translations[$unit->id->toString()] = $unit->text;
        }

        return $translations;
    }

    /**
     * @param array<string, string> $translations
     */
    private function translate(string $html, array $translations, string $target = 'pl'): string
    {
        $segmented = $this->segment($html);
        new Reassembler()->apply($segmented, $translations, Locale::fromString($target));

        return $segmented->document()->serialize();
    }
}

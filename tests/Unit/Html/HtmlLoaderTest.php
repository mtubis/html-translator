<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Html;

use HtmlTranslator\Exception\InvalidHtmlException;
use HtmlTranslator\Exception\TranslatorException;
use HtmlTranslator\Html\HtmlLoader;
use HtmlTranslator\Html\LoadedDocument;
use HtmlTranslator\Tests\Support\DomAssertions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HtmlLoader::class)]
#[CoversClass(LoadedDocument::class)]
#[CoversClass(InvalidHtmlException::class)]
final class HtmlLoaderTest extends TestCase
{
    use DomAssertions;

    /**
     * @return iterable<string, array{string}>
     */
    public static function documents(): iterable
    {
        yield 'doctype' => ['<!DOCTYPE html><p>x</p>'];
        yield 'lower-case doctype' => ['<!doctype html><p>x</p>'];
        yield 'leading whitespace' => ["\n\t <!DOCTYPE html><p>x</p>"];
        yield 'byte order mark' => ["\u{FEFF}<!DOCTYPE html><p>x</p>"];
        yield 'comment before doctype' => ["<!-- generated -->\n<!DOCTYPE html><p>x</p>"];
        yield 'html element without doctype' => ['<html lang="pl"><body><p>x</p></body></html>'];
        yield 'upper-case html element' => ['<HTML><BODY><P>x</P></BODY></HTML>'];
        yield 'head element' => ['<head><title>x</title></head><p>x</p>'];
        yield 'body element' => ['<body class="home"><p>x</p></body>'];
        yield 'bare html tag' => ['<html>x</html>'];
        yield 'XML declaration before doctype' => ["<?xml version=\"1.0\"?>\n<!DOCTYPE html><html lang=\"en\"><p>x</p></html>"];
        yield 'doctype without space' => ['<!DOCTYPEhtml><p>x</p>'];
        yield 'many leading comments' => [str_repeat("<!-- c -->\n", 50) . '<!DOCTYPE html><p>x</p>'];
        yield 'comment containing markup' => ['<!-- <p>old</p> <body> --><!DOCTYPE html><p>x</p>'];
        yield 'abruptly closed empty comment' => ['<!--><!DOCTYPE html><p>x</p>'];
        yield 'abruptly closed comment with dash' => ['<!---><!DOCTYPE html><p>x</p>'];
    }

    /**
     * A regex with a lazy comment match hits pcre.backtrack_limit on long comments and silently
     * reports "no match"; detection must stay linear.
     */
    #[Test]
    public function it_detects_documents_after_a_very_long_leading_comment(): void
    {
        $loaded = new HtmlLoader()->load('<!--' . str_repeat('x', 2_000_000) . '--><!DOCTYPE html><html lang="en"><p>x</p></html>');

        self::assertFalse($loaded->isFragment());
        self::assertSame('en', $loaded->root()->getAttribute('lang'));
    }

    #[Test]
    #[DataProvider('documents')]
    public function it_detects_full_documents(string $html): void
    {
        $loaded = new HtmlLoader()->load($html);

        self::assertFalse($loaded->isFragment());
        self::assertSame('html', $loaded->root()->localName);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fragments(): iterable
    {
        yield 'paragraph' => ['<p>x</p>'];
        yield 'plain text' => ['Just text'];
        yield 'header is not head' => ['<header>x</header>'];
        yield 'custom element starting with html' => ['<html-widget>x</html-widget>'];
        yield 'custom element starting with body' => ['<body-text>x</body-text>'];
        yield 'doctype later in the text' => ['<p>x</p><!DOCTYPE html>'];
        yield 'comment only before a paragraph' => ['<!-- c --><p>x</p>'];
        yield 'many comments before a paragraph' => [str_repeat('<!-- c -->', 50) . '<p>x</p>'];
        yield 'unclosed comment' => ['<!-- x <html>'];
        yield 'processing instruction before a paragraph' => ['<?pi x?><p>x</p>'];
    }

    #[Test]
    #[DataProvider('fragments')]
    public function it_detects_fragments(string $html): void
    {
        self::assertTrue(new HtmlLoader()->load($html)->isFragment());
    }

    #[Test]
    public function its_fragment_root_contains_the_fragment_nodes(): void
    {
        $root = new HtmlLoader()->load('<p>a</p><p>b</p>')->root();

        self::assertSame(2, $root->childElementCount);
        self::assertSame('p', $root->firstElementChild?->localName);
        self::assertSame('ab', $root->textContent);
    }

    /**
     * Document-wide queries and absolute XPath (used later by skip rules, Segmenter and Reassembler)
     * must behave the same for fragments as for documents.
     */
    #[Test]
    public function its_fragment_root_is_attached_to_its_document(): void
    {
        $root = new HtmlLoader()->load('<p>a</p>')->root();

        self::assertTrue($root->isConnected);
        self::assertNotNull($root->ownerDocument?->querySelector('p'));
    }

    /**
     * @return iterable<string, array{string, string}> fragment, element the fragment is parsed in
     */
    public static function tablePartFragments(): iterable
    {
        yield 'rows' => ['<tr><th>Plan</th><td>Price <b>9 €</b></td></tr><tr><td>Pro</td><td>19 €</td></tr>', 'tbody'];
        yield 'cells' => ['<td>A</td><th scope="row">B</th>', 'tr'];
        yield 'header cell first' => ['<th>Name</th><td>Value</td>', 'tr'];
        yield 'sections' => ['<thead><tr><th>H</th></tr></thead><tbody><tr><td>D</td></tr></tbody><tfoot><tr><td>F</td></tr></tfoot>', 'table'];
        yield 'body section only' => ['<tbody><tr><td>D</td></tr></tbody>', 'table'];
        yield 'foot section only' => ['<tfoot><tr><td>F</td></tr></tfoot>', 'table'];
        yield 'caption and column group' => ['<caption>Prices</caption><colgroup><col span="2"></colgroup>', 'table'];
        yield 'columns' => ['<col span="2"><col class="price">', 'colgroup'];
        yield 'upper-case tag' => ['<TR><TD>A</TD></TR>', 'tbody'];
        yield 'whitespace and comments first' => ["\n  <!-- rows --><?pi x?>\n<tr><td>A</td></tr>\n", 'tbody'];
        yield 'rows followed by other content' => ['<tr><td>A</td></tr><p>After</p>', 'tbody'];
    }

    #[Test]
    #[DataProvider('tablePartFragments')]
    public function it_keeps_table_parts_at_the_start_of_a_fragment(string $html, string $context): void
    {
        $loaded = new HtmlLoader()->load($html);

        self::assertTrue($loaded->isFragment());
        self::assertSame($context, $loaded->root()->localName);
        self::assertTrue($loaded->root()->isConnected);
        self::assertSame(
            str_replace(['<TR>', '</TR>', '<TD>', '</TD>', '<?pi x?>'], ['<tr>', '</tr>', '<td>', '</td>', '<!--?pi x?-->'], $html),
            $loaded->serialize(),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function bodyContextFragments(): iterable
    {
        yield 'table part after other content' => ['<p>x</p><tr><td>y</td></tr>', '<p>x</p>y'];
        yield 'custom element starting with tr' => ['<tr-row>x</tr-row>', '<tr-row>x</tr-row>'];
        yield 'custom element starting with col' => ['<col-span>x</col-span>', '<col-span>x</col-span>'];
        yield 'table part after text' => ['Total <td>9</td>', 'Total 9'];
        yield 'a whole table' => ['<table><tr><td>A</td></tr></table>', '<table><tbody><tr><td>A</td></tr></tbody></table>'];
    }

    #[Test]
    #[DataProvider('bodyContextFragments')]
    public function it_parses_other_fragments_in_a_body_as_before(string $html, string $expected): void
    {
        $loaded = new HtmlLoader()->load($html);

        self::assertSame('body', $loaded->root()->localName);
        self::assertSame($expected, $loaded->serialize());
    }

    #[Test]
    public function it_detects_table_parts_after_a_very_long_leading_comment(): void
    {
        $loaded = new HtmlLoader()->load('<!--' . str_repeat('x', 2_000_000) . '--><tr><td>A</td></tr>');

        self::assertSame('tbody', $loaded->root()->localName);
    }

    #[Test]
    public function it_does_not_wrap_fragments_on_serialization(): void
    {
        $html = '<p>Hello <b>world</b></p><img src="a.png" alt="A">';

        $serialized = new HtmlLoader()->load($html)->serialize();

        self::assertSame($html, $serialized);
        self::assertStringNotContainsStringIgnoringCase('<html', $serialized);
        self::assertStringNotContainsStringIgnoringCase('<head', $serialized);
        self::assertStringNotContainsStringIgnoringCase('<body', $serialized);
    }

    #[Test]
    public function it_keeps_surrounding_whitespace_of_fragments(): void
    {
        $html = "\n  <p>x</p>\t\n";

        self::assertSame($html, new HtmlLoader()->load($html)->serialize());
    }

    #[Test]
    public function it_serializes_full_documents_with_doctype(): void
    {
        $serialized = new HtmlLoader()->load('<!DOCTYPE html><title>T</title><p>x</p>')->serialize();

        self::assertSame('<!DOCTYPE html><html><head><title>T</title></head><body><p>x</p></body></html>', $serialized);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function multibyteTexts(): iterable
    {
        yield 'Polish' => ['Zażółć gęślą jaźń'];
        yield 'German' => ['Straße, Größe, Übung'];
        yield 'Japanese' => ['日本語のテキスト'];
        yield 'emoji with ZWJ and flags' => ['😀 👩‍💻 🇵🇱'];
    }

    #[Test]
    #[DataProvider('multibyteTexts')]
    public function it_keeps_multibyte_text_in_fragments_byte_for_byte(string $text): void
    {
        $html = '<p>' . $text . '</p>';

        self::assertSame($html, new HtmlLoader()->load($html)->serialize());
    }

    #[Test]
    #[DataProvider('multibyteTexts')]
    public function it_keeps_multibyte_text_in_documents_byte_for_byte(string $text): void
    {
        $loaded = new HtmlLoader()->load('<!DOCTYPE html><title>' . $text . '</title><p>' . $text . '</p>');

        self::assertSame($text . $text, $loaded->root()->textContent);
        self::assertStringContainsString('<p>' . $text . '</p>', $loaded->serialize());
    }

    #[Test]
    public function it_decodes_input_as_utf8_regardless_of_meta_charset(): void
    {
        $loaded = new HtmlLoader()->load('<!DOCTYPE html><meta charset="windows-1252"><p>Zażółć 😀</p>');

        self::assertStringContainsString('<p>Zażółć 😀</p>', $loaded->serialize());
    }

    #[Test]
    public function it_replaces_invalid_utf8_in_fragments_instead_of_failing(): void
    {
        self::assertSame("<p>a\u{FFFD}(b</p>", new HtmlLoader()->load("<p>a\xC3\x28b</p>")->serialize());
    }

    #[Test]
    public function it_replaces_invalid_utf8_in_documents_instead_of_failing(): void
    {
        self::assertSame("a\u{FFFD}(b", new HtmlLoader()->load("<!DOCTYPE html><p>a\xC3\x28b</p>")->root()->textContent);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedHtml(): iterable
    {
        yield 'fragment' => ['<p><b>x</p></i>', '<p><b>x</b></p>'];
        yield 'document' => ['<!DOCTYPE html><p><b>x</p></i>', '<!DOCTYPE html><html><head></head><body><p><b>x</b></p></body></html>'];
    }

    /**
     * failOnWarning in phpunit.xml.dist turns any parser warning into a failure.
     */
    #[Test]
    #[DataProvider('malformedHtml')]
    public function it_repairs_malformed_html_without_warnings(string $html, string $expected): void
    {
        self::assertSame($expected, new HtmlLoader()->load($html)->serialize());
    }

    #[Test]
    public function it_serializes_changes_made_through_the_root(): void
    {
        $loaded = new HtmlLoader()->load('<p>Hello</p>');
        $paragraph = $loaded->root()->firstElementChild;
        self::assertNotNull($paragraph);

        $paragraph->textContent = 'Cześć';

        self::assertSame('<p>Cześć</p>', $loaded->serialize());
    }

    #[Test]
    public function it_serializes_document_changes_made_through_the_root(): void
    {
        $loaded = new HtmlLoader()->load('<!DOCTYPE html><html lang="en"><body><p>x</p></body></html>');

        $loaded->root()->setAttribute('lang', 'pl');

        self::assertSameDocumentStructure('<!DOCTYPE html><html lang="pl"><body><p>x</p></body></html>', $loaded->serialize());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyInputs(): iterable
    {
        yield 'empty' => [''];
        yield 'HTML whitespace' => [" \t\n\f\r"];
        yield 'byte order mark only' => ["\u{FEFF}"];
        yield 'byte order mark and whitespace' => ["\u{FEFF} \n"];
    }

    #[Test]
    #[DataProvider('emptyInputs')]
    public function it_rejects_empty_input(string $html): void
    {
        $this->expectException(InvalidHtmlException::class);

        new HtmlLoader()->load($html);
    }

    #[Test]
    public function its_exception_is_a_translator_exception(): void
    {
        $this->expectException(TranslatorException::class);

        new HtmlLoader()->load('   ');
    }
}

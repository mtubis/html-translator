<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Support;

use HtmlTranslator\Tests\Support\DomAssertions;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The round-trip guarantee is only as strong as this helper, so it is tested on its own.
 */
#[CoversNothing]
final class DomAssertionsTest extends TestCase
{
    use DomAssertions;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function equivalentFragments(): iterable
    {
        yield 'identical' => ['<p class="a">x</p>', '<p class="a">x</p>'];
        yield 'attribute order' => ['<a href="/" class="c">x</a>', '<a class="c" href="/">x</a>'];
        yield 'quoting style' => ["<img alt='a'>", '<img alt="a">'];
        yield 'entity vs character' => ['<p>a&nbsp;b &amp; c</p>', "<p>a\u{00A0}b &amp; c</p>"];
        yield 'tag case' => ['<P>x</P>', '<p>x</p>'];
    }

    #[Test]
    #[DataProvider('equivalentFragments')]
    public function it_accepts_equivalent_fragments(string $expected, string $actual): void
    {
        self::assertSameFragmentStructure($expected, $actual);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function differentFragments(): iterable
    {
        yield 'text' => ['<p>x</p>', '<p>y</p>'];
        yield 'whitespace in text' => ['<p>a b</p>', '<p>a  b</p>'];
        yield 'surrounding whitespace' => ['<p>x</p>', "<p>x</p>\n"];
        yield 'attribute value' => ['<a href="/a">x</a>', '<a href="/b">x</a>'];
        yield 'missing attribute' => ['<a href="/" class="c">x</a>', '<a href="/">x</a>'];
        yield 'element name' => ['<b>x</b>', '<strong>x</strong>'];
        yield 'nesting' => ['<p><b>x</b> y</p>', '<p><b>x y</b></p>'];
        yield 'comment' => ['<!-- a --><p>x</p>', '<!-- b --><p>x</p>'];
        yield 'template content' => ['<template><p>a</p></template>', '<template><p>b</p></template>'];
        yield 'multibyte text' => ['<p>Zażółć</p>', '<p>Zazolc</p>'];
        yield 'added document wrapper' => ['<p>x</p>', '<html><head></head><body><p>x</p></body></html>'];
        yield 'added closing body' => ['<p>x</p>', '<p>x</p></body></html>'];
    }

    #[Test]
    #[DataProvider('differentFragments')]
    public function it_rejects_different_fragments(string $expected, string $actual): void
    {
        $this->expectException(AssertionFailedError::class);

        self::assertSameFragmentStructure($expected, $actual);
    }

    #[Test]
    public function it_accepts_documents_differing_only_in_implied_elements(): void
    {
        self::assertSameDocumentStructure(
            '<!DOCTYPE html><title>T</title><p>x</p>',
            '<!DOCTYPE html><html><head><title>T</title></head><body><p>x</p></body></html>',
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function differentDocuments(): iterable
    {
        yield 'doctype' => ['<!DOCTYPE html><p>x</p>', '<p>x</p>'];
        yield 'html attribute' => ['<html lang="en"><p>x</p></html>', '<html lang="pl"><p>x</p></html>'];
        yield 'head content' => ['<title>A</title>', '<title>B</title>'];
    }

    #[Test]
    #[DataProvider('differentDocuments')]
    public function it_rejects_different_documents(string $expected, string $actual): void
    {
        $this->expectException(AssertionFailedError::class);

        self::assertSameDocumentStructure($expected, $actual);
    }
}

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

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sameSkeletons(): iterable
    {
        yield 'other wording' => ['<p>Hello <a href="/x">world</a></p>', '<p>Cześć <a href="/x">świecie</a>, miło</p>'];
        yield 'moved inline tags' => ['<p><b>a</b> and <i>b</i></p>', '<p><i>b</i> i <b>a</b></p>'];
        yield 'translated attributes' => ['<img src="a.png" alt="A cat">', '<img src="a.png" alt="Kot">'];
        yield 'text split differently' => ['<p><b>a</b> b</p>', '<p>x <b>a</b></p>'];
        yield 'untouched code' => ['<p>Run <code>make test</code></p>', '<p>Uruchom <code>make test</code> teraz</p>'];
        yield 'translation switched back on' => ['<div translate="no">Acme <span translate="yes">Hello</span></div>', '<div translate="no">Acme <span translate="yes">Cześć</span></div>'];
    }

    #[Test]
    #[DataProvider('sameSkeletons')]
    public function it_accepts_the_same_skeleton(string $expected, string $actual): void
    {
        self::assertSameSkeleton($expected, $actual, true);
    }

    #[Test]
    public function it_accepts_the_same_document_skeleton(): void
    {
        self::assertSameSkeleton(
            '<!DOCTYPE html><html lang="pl"><head><title>About</title></head><body><p>Hello</p></body></html>',
            '<!DOCTYPE html><html lang="pl"><head><title>O nas</title></head><body><p>Cześć</p></body></html>',
            false,
        );
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function differentSkeletons(): iterable
    {
        yield 'lost element' => ['<p>a <b>b</b></p>', '<p>a b</p>', true];
        yield 'gained element' => ['<p>a b</p>', '<p>a <b>b</b></p>', true];
        yield 'duplicated element' => ['<p><b>a</b></p>', '<p><b>a</b><b>a</b></p>', true];
        yield 'changed code' => ['<p>Run <code>make test</code></p>', '<p>Uruchom <code>zrób test</code></p>', true];
        yield 'translated text under translate="no"' => ['<p translate="no">Acme Corp</p>', '<p translate="no">Firma Acme</p>', true];
        yield 'changed script' => ['<script>var a = 1;</script>', '<script>var a = 2;</script>', true];
        yield 'changed nesting' => ['<p><b><i>a</i></b></p>', '<p><b>a</b><i>a</i></p>', true];
        yield 'changed link' => ['<a href="/x">a</a>', '<a href="/y">a</a>', true];
        yield 'changed class' => ['<p class="a">x</p>', '<p class="b">x</p>', true];
        yield 'changed comment' => ['<p>x<!-- a --></p>', '<p>x<!-- b --></p>', true];
        yield 'fragment gained a body' => ['<p>x</p>', '<body><p>x</p></body>', true];
        yield 'changed document language' => ['<html lang="pl"><body><p>x</p></body></html>', '<html lang="en"><body><p>x</p></body></html>', false];
    }

    #[Test]
    #[DataProvider('differentSkeletons')]
    public function it_rejects_a_different_skeleton(string $expected, string $actual, bool $fragment): void
    {
        $this->expectException(AssertionFailedError::class);

        self::assertSameSkeleton($expected, $actual, $fragment);
    }
}

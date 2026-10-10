<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Html;

use Dom\Element;
use Dom\HTMLDocument;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Html\TranslatableAttributes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TranslatableAttributes::class)]
#[UsesClass(InvalidArgumentException::class)]
final class TranslatableAttributesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function defaults(): iterable
    {
        yield 'alt' => ['<img id="target" src="a.png" alt="Logo">', ['alt']];
        yield 'title on any element' => ['<span id="target" title="Hint">x</span>', ['title']];
        yield 'placeholder' => ['<input id="target" placeholder="Your name">', ['placeholder']];
        yield 'aria-label' => ['<button id="target" aria-label="Close">×</button>', ['aria-label']];
        yield 'aria-description' => ['<div id="target" aria-description="Details">x</div>', ['aria-description']];
        yield 'several in document order' => ['<img id="target" title="T" src="a.png" alt="A">', ['title', 'alt']];
        yield 'untranslatable attributes are ignored' => ['<a id="target" href="/x" class="c" data-x="y" title="Go">x</a>', ['title']];
        yield 'none' => ['<p id="target" class="c">x</p>', []];
        yield 'empty value' => ['<img id="target" src="a.png" alt="">', []];
        yield 'whitespace-only value' => ["<img id=\"target\" src=\"a.png\" alt=\" \t\n\">", []];
        // U+00A0 is content, as in TranslationUnit; whether such a unit is worth sending is the Segmenter's call.
        yield 'no-break space value' => ["<img id=\"target\" src=\"a.png\" alt=\"\u{00A0}\">", ['alt']];
        yield 'multibyte value' => ['<img id="target" src="a.png" alt="Zażółć 日本 😀">', ['alt']];

        yield 'meta description' => ['<meta id="target" name="description" content="About us">', ['content']];
        yield 'meta keywords' => ['<meta id="target" name="keywords" content="a, b">', ['content']];
        yield 'meta og:title' => ['<meta id="target" property="og:title" content="Title">', ['content']];
        yield 'meta og:description' => ['<meta id="target" property="og:description" content="Text">', ['content']];
        yield 'meta og:title in name' => ['<meta id="target" name="og:title" content="Title">', ['content']];
        yield 'meta name is case-insensitive' => ['<meta id="target" name="Description" content="About us">', ['content']];
        yield 'meta name with surrounding whitespace' => ['<meta id="target" name=" description " content="About us">', ['content']];
        yield 'meta with several RDFa properties' => ['<meta id="target" property="og:title twitter:title" content="Title">', ['content']];
        yield 'meta with other name' => ['<meta id="target" name="viewport" content="width=device-width">', []];
        yield 'meta og:url' => ['<meta id="target" property="og:url" content="https://example.com/">', []];
        yield 'meta without name' => ['<meta id="target" content="x">', []];
        yield 'meta with empty content' => ['<meta id="target" name="description" content="">', []];
        yield 'content on other elements' => ['<div id="target" name="description" content="x">y</div>', []];

        yield 'input submit value' => ['<input id="target" type="submit" value="Send">', ['value']];
        yield 'input button value' => ['<input id="target" type="button" value="Click">', ['value']];
        yield 'input type is case-insensitive' => ['<input id="target" type="SUBMIT" value="Send">', ['value']];
        yield 'input text value' => ['<input id="target" type="text" value="typed">', []];
        yield 'input without type' => ['<input id="target" value="typed">', []];
        yield 'input reset value' => ['<input id="target" type="reset" value="Reset">', []];
        yield 'value on button element' => ['<button id="target" value="v">Send</button>', []];
        yield 'value with placeholder' => ['<input id="target" type="submit" placeholder="P" value="Send">', ['placeholder', 'value']];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('defaults')]
    public function it_selects_default_attributes(string $html, array $expected): void
    {
        self::assertSame($expected, TranslatableAttributes::defaults()->namesFor($this->target($html)));
    }

    #[Test]
    public function it_selects_added_attributes_case_insensitively(): void
    {
        $attributes = TranslatableAttributes::defaults()->withAttribute('Data-Tooltip');

        self::assertSame(['data-tooltip'], $attributes->namesFor($this->target('<span id="target" data-tooltip="Hi">x</span>')));
    }

    #[Test]
    public function it_ignores_removed_attributes(): void
    {
        $attributes = TranslatableAttributes::defaults()->withoutAttribute('title');

        self::assertSame(['alt'], $attributes->namesFor($this->target('<img id="target" title="T" alt="A">')));
    }

    #[Test]
    public function it_selects_added_meta_names(): void
    {
        $attributes = TranslatableAttributes::defaults()->withMetaName('Twitter:Title');

        self::assertSame(['content'], $attributes->namesFor($this->target('<meta id="target" name="twitter:title" content="T">')));
    }

    #[Test]
    public function it_ignores_removed_meta_names(): void
    {
        $attributes = TranslatableAttributes::defaults()->withoutMetaName('keywords');

        self::assertSame([], $attributes->namesFor($this->target('<meta id="target" name="keywords" content="a, b">')));
        self::assertSame(['content'], $attributes->namesFor($this->target('<meta id="target" name="description" content="x">')));
    }

    #[Test]
    public function it_keeps_default_attributes_when_adding_attributes(): void
    {
        $attributes = TranslatableAttributes::defaults()->withAttribute('data-tooltip')->withAttribute('data-hint');

        self::assertSame(
            ['alt', 'title', 'placeholder', 'aria-label', 'aria-description', 'data-tooltip', 'data-hint'],
            $attributes->namesFor($this->target('<input id="target" alt="a" title="t" placeholder="p" aria-label="l" aria-description="d" data-tooltip="x" data-hint="h">')),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function defaultMetaNames(): iterable
    {
        foreach (['description', 'keywords', 'og:title', 'og:description'] as $name) {
            yield $name => [$name];
        }
    }

    #[Test]
    #[DataProvider('defaultMetaNames')]
    public function it_keeps_default_meta_names_when_adding_meta_names(string $name): void
    {
        $attributes = TranslatableAttributes::defaults()->withMetaName('twitter:title');

        self::assertSame(['content'], $attributes->namesFor($this->target(\sprintf('<meta id="target" name="%s" content="x">', $name))));
    }

    #[Test]
    public function it_is_immutable(): void
    {
        $defaults = TranslatableAttributes::defaults();
        $tooltip = $this->target('<span id="target" data-tooltip="Hi" title="T">x</span>');
        $meta = $this->target('<meta id="target" name="twitter:title" content="T">');

        $changed = $defaults->withAttribute('data-tooltip')->withoutAttribute('title')->withMetaName('twitter:title');

        self::assertNotSame($defaults, $changed);
        self::assertSame(['title'], $defaults->namesFor($tooltip));
        self::assertSame([], $defaults->namesFor($meta));
        self::assertSame(['data-tooltip'], $changed->namesFor($tooltip));
        self::assertSame(['content'], $changed->namesFor($meta));
    }

    #[Test]
    public function it_ignores_duplicates_and_unknown_removals(): void
    {
        $attributes = TranslatableAttributes::defaults()
            ->withAttribute('alt')
            ->withoutAttribute('alt')
            ->withoutAttribute('data-unknown')
            ->withMetaName('description')
            ->withoutMetaName('description')
            ->withoutMetaName('unknown');

        self::assertSame([], $attributes->namesFor($this->target('<img id="target" alt="A">')));
        self::assertSame([], $attributes->namesFor($this->target('<meta id="target" name="description" content="x">')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidAttributeNames(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => [' '];
        yield 'inner space' => ['data tooltip'];
        yield 'assignment' => ['alt=x'];
        yield 'quote' => ['"alt"'];
        yield 'leading digit' => ['1x'];
    }

    #[Test]
    #[DataProvider('invalidAttributeNames')]
    public function it_rejects_invalid_attribute_names(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        TranslatableAttributes::defaults()->withAttribute($name);
    }

    #[Test]
    #[DataProvider('invalidAttributeNames')]
    public function it_rejects_invalid_attribute_names_on_removal(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        TranslatableAttributes::defaults()->withoutAttribute($name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidMetaNames(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => [' '];
        yield 'inner space' => ['twitter title'];
    }

    #[Test]
    #[DataProvider('invalidMetaNames')]
    public function it_rejects_invalid_meta_names(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        TranslatableAttributes::defaults()->withMetaName($name);
    }

    #[Test]
    #[DataProvider('invalidMetaNames')]
    public function it_rejects_invalid_meta_names_on_removal(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        TranslatableAttributes::defaults()->withoutMetaName($name);
    }

    private function target(string $html): Element
    {
        $document = HTMLDocument::createFromString('<!DOCTYPE html>' . $html, \LIBXML_NOERROR, 'UTF-8');

        // querySelector, not getElementById: PHP 8.4 does not find SVG/MathML elements by id.
        return $document->querySelector('#target') ?? self::fail('Test HTML has no #target element.');
    }
}

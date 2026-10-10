<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Html;

use Dom\Element;
use Dom\HTMLDocument;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Exception\InvalidHtmlException;
use HtmlTranslator\Html\LoadedDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LoadedDocument::class)]
#[CoversClass(InvalidHtmlException::class)]
#[UsesClass(InvalidArgumentException::class)]
final class LoadedDocumentTest extends TestCase
{
    #[Test]
    public function it_rejects_a_document_without_root_element(): void
    {
        $this->expectException(InvalidHtmlException::class);

        LoadedDocument::document(HTMLDocument::createEmpty());
    }

    #[Test]
    public function it_rejects_a_fragment_container_from_another_document(): void
    {
        $other = HTMLDocument::createEmpty();
        $container = $other->appendChild($other->createElement('body'));
        self::assertInstanceOf(Element::class, $container);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('belong');

        LoadedDocument::fragment(HTMLDocument::createEmpty(), $container);
    }

    #[Test]
    public function it_exposes_the_document_its_root_belongs_to(): void
    {
        $document = HTMLDocument::createEmpty();
        $container = $document->appendChild($document->createElement('body'));
        self::assertInstanceOf(Element::class, $container);
        $loaded = LoadedDocument::fragment($document, $container);

        self::assertSame($document, $loaded->htmlDocument());
    }

    #[Test]
    public function it_rejects_a_detached_fragment_container(): void
    {
        $document = HTMLDocument::createEmpty();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('attached');

        LoadedDocument::fragment($document, $document->createElement('body'));
    }
}

<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Html;

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
        $container = HTMLDocument::createEmpty()->createElement('body');

        $this->expectException(InvalidArgumentException::class);

        LoadedDocument::fragment(HTMLDocument::createEmpty(), $container);
    }
}

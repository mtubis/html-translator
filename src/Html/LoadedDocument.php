<?php

declare(strict_types=1);

namespace HtmlTranslator\Html;

use Dom\Element;
use Dom\HTMLDocument;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Exception\InvalidHtmlException;

/**
 * A parsed full document or fragment. The tree is live: changes made through root() show up in serialize().
 */
final readonly class LoadedDocument
{
    private function __construct(
        private HTMLDocument $document,
        private Element $root,
        private bool $fragment,
    ) {}

    /**
     * @throws InvalidHtmlException when the document has no root element
     */
    public static function document(HTMLDocument $document): self
    {
        $root = $document->documentElement ?? throw new InvalidHtmlException('The parsed document has no root element.');

        return new self($document, $root, false);
    }

    /**
     * @param Element $container element whose children are the fragment's nodes; it is never serialized itself
     *
     * @throws InvalidArgumentException when the container belongs to another document
     */
    public static function fragment(HTMLDocument $document, Element $container): self
    {
        if ($container->ownerDocument !== $document) {
            throw new InvalidArgumentException('The fragment container must belong to the given document.');
        }

        return new self($document, $container, true);
    }

    /**
     * The element to walk: <html> for a full document, the fragment container otherwise.
     */
    public function root(): Element
    {
        return $this->root;
    }

    public function isFragment(): bool
    {
        return $this->fragment;
    }

    /**
     * A full document (with doctype) for document input, only the fragment's nodes for fragment input.
     */
    public function serialize(): string
    {
        return $this->fragment ? $this->root->innerHTML : $this->document->saveHtml();
    }
}

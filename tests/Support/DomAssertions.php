<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Support;

use Dom\Comment;
use Dom\DocumentType;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;

/**
 * Compares HTML by normalized DOM tree instead of raw strings.
 *
 * Both sides are parsed with Dom\HTMLDocument directly (never through HtmlLoader), so the loader
 * is not used to verify itself. The dump keeps element names, attributes (sorted by name), text
 * (exact, whitespace included), comments and doctype; anything that changes there is a difference.
 */
trait DomAssertions
{
    public static function assertSameDocumentStructure(string $expected, string $actual): void
    {
        self::assertSame(self::dumpDocument($expected), self::dumpDocument($actual), 'Documents differ structurally.');
    }

    /**
     * Parsing in <body> context silently drops <html>/<head>/<body> tags, so the dump alone cannot see
     * a fragment that gained a document wrapper; that is checked on the raw string.
     */
    public static function assertSameFragmentStructure(string $expected, string $actual): void
    {
        self::assertDoesNotMatchRegularExpression('/<\/?(?:html|head|body)[\s>\/]/i', $actual, 'Fragment gained document-level tags.');
        self::assertSame(self::dumpFragment($expected), self::dumpFragment($actual), 'Fragments differ structurally.');
    }

    private static function dumpDocument(string $html): string
    {
        return self::dumpChildren(HTMLDocument::createFromString($html, \LIBXML_NOERROR, 'UTF-8'), 0);
    }

    private static function dumpFragment(string $html): string
    {
        $document = HTMLDocument::createFromString('<!DOCTYPE html><html><head></head><body></body></html>', \LIBXML_NOERROR, 'UTF-8');
        $body = $document->body ?? self::fail('Empty skeleton document has no <body>.');
        $body->innerHTML = $html;

        return self::dumpChildren($body, 0);
    }

    private static function dumpChildren(Node $parent, int $depth): string
    {
        $lines = [];
        foreach ($parent->childNodes as $child) {
            $lines[] = self::dumpNode($child, $depth);
        }

        return implode("\n", $lines);
    }

    private static function dumpNode(Node $node, int $depth): string
    {
        $indent = str_repeat('  ', $depth);

        if ($node instanceof DocumentType) {
            return \sprintf('%s<!DOCTYPE %s>', $indent, $node->name);
        }

        if ($node instanceof Text) {
            return $indent . '#text ' . self::quote($node->data);
        }

        if ($node instanceof Comment) {
            return $indent . '#comment ' . self::quote($node->data);
        }

        if ($node instanceof Element) {
            $attributes = [];
            foreach ($node->attributes as $attribute) {
                $attributes[$attribute->name] = \sprintf(' %s=%s', $attribute->name, self::quote($attribute->value));
            }

            ksort($attributes);

            $line = \sprintf('%s<%s %s%s>', $indent, $node->namespaceURI ?? '', $node->localName, implode('', $attributes));

            // PHP 8.4 does not expose HTMLTemplateElement::$content; its children are not in childNodes.
            // The serialized content is compared as a string, so attribute order inside templates matters.
            if ('template' === $node->localName) {
                $line .= ' content=' . self::quote($node->innerHTML);
            }

            $children = self::dumpChildren($node, $depth + 1);

            return '' === $children ? $line : $line . "\n" . $children;
        }

        return $indent . '#' . $node::class;
    }

    private static function quote(string $value): string
    {
        return json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }
}

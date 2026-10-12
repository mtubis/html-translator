<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Support;

use Dom\Comment;
use Dom\DocumentType;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;
use HtmlTranslator\Html\SkipRules;
use HtmlTranslator\Html\TranslatableAttributes;

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

    /**
     * For translations by real models, where wording is free: the same elements, nesting and untranslated attributes.
     * Text and the values of translatable attributes are ignored, except where nothing is translated (subtrees skipped
     * by SkipRules such as <code>, and translate="no"), which must come back exactly. Sibling order is ignored at every
     * level, because a model may move inline tags to fit the word order; block order is left to the golden fixtures.
     */
    public static function assertSameSkeleton(string $expected, string $actual, bool $fragment): void
    {
        if ($fragment) {
            self::assertDoesNotMatchRegularExpression('/<\/?(?:html|head|body)[\s>\/]/i', $actual, 'Fragment gained document-level tags.');
        }

        $skipRules = SkipRules::defaults();
        $attributes = TranslatableAttributes::defaults();
        $skeleton = static fn(string $html): string => self::dumpSkeleton($fragment ? self::parseFragment($html) : self::parseDocument($html), 0, true, $skipRules, $attributes);

        self::assertSame($skeleton($expected), $skeleton($actual), 'Skeletons differ.');
    }

    private static function dumpDocument(string $html): string
    {
        return self::dumpChildren(self::parseDocument($html), 0);
    }

    private static function dumpFragment(string $html): string
    {
        return self::dumpChildren(self::parseFragment($html), 0);
    }

    private static function parseDocument(string $html): HTMLDocument
    {
        return HTMLDocument::createFromString($html, \LIBXML_NOERROR, 'UTF-8');
    }

    /**
     * Parsed in a <body>, or for a fragment that opens with a table part in the element it belongs in, so lost
     * rows and cells show up as a difference. Deliberately independent of HtmlLoader's own detection.
     */
    private static function parseFragment(string $html): Element
    {
        $document = HTMLDocument::createFromString('<!DOCTYPE html><html><head></head><body></body></html>', \LIBXML_NOERROR, 'UTF-8');
        $container = $document->body ?? self::fail('Empty skeleton document has no <body>.');

        // Possessive and without lazy quantifiers, so a long leading comment cannot exhaust the backtrack limit.
        $tablePart = preg_match('/^(?:\s++|<!--[^-]*+(?:-(?!->)[^-]*+)*+-->|<\?[^>]*+>)*+<(tr|td|th|thead|tbody|tfoot|caption|colgroup|col)[\s>\/]/i', $html, $match);
        if (false === $tablePart) {
            self::fail('Could not check the fragment for a table part: ' . preg_last_error_msg());
        }

        if (1 === $tablePart) {
            $chain = match (strtolower($match[1])) {
                'tr' => ['table', 'tbody'],
                'td', 'th' => ['table', 'tbody', 'tr'],
                'col' => ['table', 'colgroup'],
                default => ['table'],
            };
            foreach ($chain as $name) {
                $element = $document->createElement($name);
                $container->appendChild($element);
                $container = $element;
            }
        }

        $container->innerHTML = $html;

        return $container;
    }

    private static function dumpSkeleton(Node $parent, int $depth, bool $translates, SkipRules $skipRules, TranslatableAttributes $attributes): string
    {
        $lines = [];
        foreach ($parent->childNodes as $child) {
            if ($child instanceof Text) {
                if (!$translates) {
                    $lines[] = self::dumpNode($child, $depth);
                }

                continue;
            }

            if (!$child instanceof Element || $skipRules->skipsSubtree($child)) {
                $lines[] = self::dumpNode($child, $depth);

                continue;
            }

            $childTranslates = $skipRules->translates($child, $translates);
            $ignored = $childTranslates ? $attributes->namesFor($child) : [];
            $dumped = [];
            foreach ($child->attributes as $attribute) {
                $value = \in_array($attribute->name, $ignored, true) ? '…' : self::quote($attribute->value);
                $dumped[$attribute->name] = \sprintf(' %s=%s', $attribute->name, $value);
            }

            ksort($dumped);
            $line = \sprintf('%s<%s %s%s>', str_repeat('  ', $depth), $child->namespaceURI ?? '', $child->localName, implode('', $dumped));
            $children = self::dumpSkeleton($child, $depth + 1, $childTranslates, $skipRules, $attributes);
            $lines[] = '' === $children ? $line : $line . "\n" . $children;
        }

        sort($lines);

        return implode("\n", $lines);
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

<?php

declare(strict_types=1);

namespace HtmlTranslator\Html;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use HtmlTranslator\Domain\InlineTagKind;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Domain\UnitText;
use HtmlTranslator\Domain\UnitTextToken;
use HtmlTranslator\Domain\UnitTextTokenKind;
use HtmlTranslator\Exception\InvalidArgumentException;

/**
 * Writes translations back into the segmented document (ADR-0003, ARCHITECTURE §6.6).
 *
 * - Translations are decoded with UnitText::tokenize() (ADR-0009). Text, including anything that looks like
 *   markup, becomes text nodes only, and never inside raw-text elements (script, iframe, …), whose text is
 *   serialized unescaped: model output never becomes markup.
 * - Pseudo-tags become clones of the original nodes: <gN> shallow (its content comes from the translation),
 *   <xN/> deep (void elements, and opaque elements or comments with their original subtree, template
 *   contents included).
 * - Attribute units are applied before runs are replaced, so clones carry translated attributes; their
 *   surrounding whitespace is restored like that of runs.
 * - An <option> without value whose text changes gets its original text as value, so the form still submits
 *   the same data.
 * - Documents get lang (and dir: rtl for right-to-left targets, ltr when a dir was present); fragments have
 *   no element for them and are left alone.
 *
 * Call apply() once per segmented document, with the complete map: replaced nodes leave the document, so a
 * later call cannot reach them. Every translation and anchor is checked before the DOM is touched (a safety
 * net; retry-facing validation is StructureValidator's job), so a failing call changes nothing.
 */
final readonly class Reassembler
{
    private const string HTML_WHITESPACE = " \t\n\f\r";

    /**
     * @param array<string, string> $translations unit id → translated unit text; units not in the map stay untouched
     *
     * @throws InvalidArgumentException when an id is unknown, a translation does not match its unit's tags,
     *                                  or a unit's nodes are no longer part of the document
     */
    public function apply(SegmentedDocument $segmented, array $translations, Locale $target): void
    {
        $units = [];
        foreach ($segmented->units() as $unit) {
            $units[$unit->id->toString()] = $unit;
        }

        $attributeChanges = [];
        $runChanges = [];
        $claimed = [];

        foreach ($translations as $id => $translated) {
            $unit = $units[$id] ?? throw new InvalidArgumentException(\sprintf('Unknown unit id "%s".', $id));
            $anchor = $segmented->anchorFor($unit->id);
            $tokens = UnitText::tokenize($translated);

            if ($anchor instanceof AttributeAnchor) {
                $this->assertConnected($unit, $anchor->element);
                $attributeChanges[] = [$anchor, $unit->leadingWhitespace . $this->plainText($unit, $tokens) . $unit->trailingWhitespace];
            } else {
                $this->assertConnected($unit, ...$anchor->nodes);
                $this->assertReplaceable($unit, $anchor, $claimed);
                $this->assertMatchesSource($unit, $anchor, $tokens);
                $runChanges[] = [$unit, $anchor, $tokens];
            }
        }

        foreach ($attributeChanges as [$anchor, $value]) {
            $anchor->element->setAttribute($anchor->attribute, $value);
        }

        $dom = $segmented->document()->htmlDocument();
        foreach ($runChanges as [$unit, $anchor, $tokens]) {
            $this->replaceRun($dom, $unit, $anchor, $tokens);
        }

        $this->setLanguage($segmented->document(), $target);
    }

    private function assertConnected(TranslationUnit $unit, Node ...$nodes): void
    {
        foreach ($nodes as $node) {
            if (!$node->isConnected) {
                throw new InvalidArgumentException(\sprintf('Unit "%s" is no longer part of the document; call apply() once with all translations.', $unit->id->toString()));
            }
        }
    }

    /**
     * The run's nodes are siblings under an element that may hold model text, and no other run claims them,
     * so replacing them cannot fail half-way (the Segmenter guarantees this; hand-built anchors may not).
     *
     * @param array<int, true> $claimed spl_object_id() of nodes of runs checked so far (the anchors keep them alive)
     *
     * @param-out array<int, true> $claimed
     */
    private function assertReplaceable(TranslationUnit $unit, RunAnchor $anchor, array &$claimed): void
    {
        $parent = $anchor->nodes[0]->parentNode ?? null;

        if ($parent instanceof Element && \in_array($parent->localName, SkipRules::ALWAYS_SKIPPED_ELEMENTS, true)) {
            throw new InvalidArgumentException(\sprintf('Unit "%s" lies inside a <%s> element, which must not receive translated text.', $unit->id->toString(), $parent->localName));
        }

        foreach ($anchor->nodes as $node) {
            if ($node->parentNode !== $parent || isset($claimed[spl_object_id($node)])) {
                throw new InvalidArgumentException(\sprintf('The nodes of unit "%s" are not a run of siblings of its own.', $unit->id->toString()));
            }

            $claimed[spl_object_id($node)] = true;
        }
    }

    /**
     * @param list<UnitTextToken> $tokens
     */
    private function plainText(TranslationUnit $unit, array $tokens): string
    {
        foreach ($tokens as $token) {
            if (null !== $token->number) {
                throw new InvalidArgumentException(\sprintf('The translation of unit "%s" must not contain tags.', $unit->id->toString()));
            }
        }

        return $this->plainTextOf($tokens);
    }

    /**
     * Every source tag exactly once, in its own form, properly nested; every tag mapped to a usable node.
     *
     * @param list<UnitTextToken> $tokens
     */
    private function assertMatchesSource(TranslationUnit $unit, RunAnchor $anchor, array $tokens): void
    {
        $expected = [];
        foreach ($unit->inlineTags as $tag) {
            $node = $anchor->tags[$tag->number] ?? null;
            $usable = InlineTagKind::Paired === $tag->kind
                ? $node instanceof Element && !\in_array($node->localName, SkipRules::ALWAYS_SKIPPED_ELEMENTS, true)
                : $node instanceof Node;

            if (!$usable) {
                throw new InvalidArgumentException(\sprintf('Tag %d of unit "%s" has no usable original node.', $tag->number, $unit->id->toString()));
            }

            $expected[$tag->number] = $tag->kind;
        }

        $seen = [];
        $open = [];
        foreach ($tokens as $token) {
            $number = $token->number;
            if (null === $number) {
                continue;
            }

            $kind = $expected[$number] ?? null;

            if (UnitTextTokenKind::Close === $token->kind) {
                if (array_pop($open) !== $number) {
                    $this->mismatch($unit);
                }

                continue;
            }

            $paired = UnitTextTokenKind::Open === $token->kind;
            if (null === $kind || isset($seen[$number]) || $paired !== (InlineTagKind::Paired === $kind)) {
                $this->mismatch($unit);
            }

            $seen[$number] = true;
            if ($paired) {
                $open[] = $number;
            }
        }

        if ([] !== $open || \count($seen) !== \count($expected)) {
            $this->mismatch($unit);
        }
    }

    private function mismatch(TranslationUnit $unit): never
    {
        throw new InvalidArgumentException(\sprintf('The translation of unit "%s" does not match the tags of its source; validate it before reassembly.', $unit->id->toString()));
    }

    /**
     * Text is buffered and written as one node per stretch: appending to a text node token by token is quadratic.
     *
     * @param list<UnitTextToken> $tokens checked by assertMatchesSource()
     */
    private function replaceRun(HTMLDocument $dom, TranslationUnit $unit, RunAnchor $anchor, array $tokens): void
    {
        // Checked in apply(): the anchor has nodes (RunAnchor) and they are connected.
        $first = $anchor->nodes[0] ?? null;
        $parent = $first?->parentNode;
        if (!$first instanceof Node || !$parent instanceof Node) {
            throw new InvalidArgumentException(\sprintf('Unit "%s" is no longer part of the document.', $unit->id->toString()));
        }

        $this->keepOptionValue($first, $this->plainTextOf($tokens));

        $fragment = $dom->createDocumentFragment();
        $current = $fragment;
        $stack = [];
        $buffer = $unit->leadingWhitespace;

        foreach ($tokens as $token) {
            if (null === $token->number) {
                $buffer .= $token->text;

                continue;
            }

            $this->flushText($dom, $current, $buffer);
            $original = $anchor->tags[$token->number] ?? null;

            if (UnitTextTokenKind::Close === $token->kind) {
                $current = array_pop($stack) ?? $fragment;
            } elseif (UnitTextTokenKind::Open === $token->kind && $original instanceof Node) {
                $stack[] = $current;
                $current = $current->appendChild($original->cloneNode(false));
            } elseif ($original instanceof Node) {
                $current->appendChild($this->deepClone($original));
            }
        }

        $buffer .= $unit->trailingWhitespace;
        $this->flushText($dom, $current, $buffer);

        $parent->insertBefore($fragment, $first);
        foreach ($anchor->nodes as $node) {
            $parent->removeChild($node);
        }
    }

    private function flushText(HTMLDocument $dom, Node $parent, string &$buffer): void
    {
        if ('' !== $buffer) {
            $parent->appendChild($dom->createTextNode($buffer));
            $buffer = '';
        }
    }

    /**
     * cloneNode(true) in PHP 8.4 leaves template contents behind; they are copied from the original markup.
     */
    private function deepClone(Node $original): Node
    {
        $clone = $original->cloneNode(true);

        if ($original instanceof Element && $clone instanceof Element) {
            $originals = $this->templatesOf($original);
            foreach ($this->templatesOf($clone) as $index => $template) {
                $template->innerHTML = isset($originals[$index]) ? $originals[$index]->innerHTML : '';
            }
        }

        return $clone;
    }

    /**
     * @return list<Element> the element itself if it is a template, then template descendants in document order
     */
    private function templatesOf(Element $element): array
    {
        $templates = 'template' === $element->localName ? [$element] : [];

        return [...$templates, ...$element->querySelectorAll('template')];
    }

    /**
     * @param list<UnitTextToken> $tokens
     */
    private function plainTextOf(array $tokens): string
    {
        $text = '';
        foreach ($tokens as $token) {
            // Text and raw markup (which stays text); pseudo-tags carry a number.
            if (null === $token->number) {
                $text .= $token->text;
            }
        }

        return $text;
    }

    /**
     * An option without value submits its text, so when the translation changes that text, the original
     * becomes the value (whitespace stripped and collapsed, as the browser does). An option holds a single
     * run, so the run's text is the option's whole text.
     */
    private function keepOptionValue(Node $runNode, string $newText): void
    {
        $option = $runNode->parentElement?->closest('option');
        if (!$option instanceof Element || $option->hasAttribute('value')) {
            return;
        }

        $original = $this->collapseWhitespace($option->textContent ?? '');
        if ($original !== $this->collapseWhitespace($newText)) {
            $option->setAttribute('value', $original);
        }
    }

    private function collapseWhitespace(string $text): string
    {
        $trimmed = trim($text, self::HTML_WHITESPACE);

        return preg_replace('/[ \t\n\f\r]+/', ' ', $trimmed) ?? $trimmed;
    }

    private function setLanguage(LoadedDocument $document, Locale $target): void
    {
        if ($document->isFragment()) {
            return;
        }

        $root = $document->root();
        $root->setAttribute('lang', $target->toString());

        if ($target->isRightToLeft()) {
            $root->setAttribute('dir', 'rtl');
        } elseif ($root->hasAttribute('dir')) {
            $root->setAttribute('dir', 'ltr');
        }
    }
}

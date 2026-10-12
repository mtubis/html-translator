<?php

declare(strict_types=1);

namespace HtmlTranslator\Html;

use Dom\Comment;
use Dom\Element;
use Dom\Node;
use Dom\Text;
use HtmlTranslator\Domain\UnitKind;
use HtmlTranslator\Domain\UnitText;
use HtmlTranslator\Exception\InvalidHtmlException;
use SplObjectStorage;
use WeakMap;

/**
 * Splits a document into translation units (ARCHITECTURE §6.2).
 *
 * A block unit is a maximal run of inline content (text and inline elements) directly inside a block
 * container that holds translatable text. Blocks split runs, so mixed content such as
 * `<div>Intro <p>Para</p> outro</div>` yields three units, and nested blocks yield units only at the
 * innermost text-bearing level. Details:
 * - Unknown and custom elements are inline, as in browsers; an inline element that contains a block
 *   acts as a container.
 * - Whitespace-only text and comments at the edges of a run stay in the DOM and out of the unit.
 * - A run that is a single inline element with content is unwrapped (`<li><a>Link</a></li>` → "Link");
 *   the element is then a container, so translate="yes" inside a lone translate="no" element is honoured.
 * - The tree is walked top-down: element rules prune content and subtrees (the element's own attributes still
 *   become units), the translate state is carried down,
 *   and below a non-translating container elements are still visited, so translate="yes" re-enables them.
 * - Attribute units of an element come before its content; those of inline elements follow their run.
 *
 * Context hints (plain text, whitespace collapsed, at most 200 characters): the document title and, per unit, the
 * nearest h1–h6 before it, whether or not that heading is translated, also inside a skipped subtree. A heading
 * inside a skipped inline element within a sentence (opaque, never visited) is not seen; real markup has none.
 */
final readonly class Segmenter
{
    private const array BLOCK_ELEMENTS = [
        'address', 'article', 'aside', 'blockquote', 'body', 'caption', 'colgroup', 'dd', 'details', 'dialog',
        'div', 'dl', 'dt', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5',
        'h6', 'head', 'header', 'hgroup', 'hr', 'html', 'legend', 'li', 'main', 'menu', 'nav', 'ol', 'optgroup',
        'option', 'p', 'pre', 'search', 'section', 'summary', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
    ];

    /**
     * The walk recurses per nesting level; deeper input is rejected instead of exhausting the call stack.
     * Real pages stay far below this.
     */
    private const int MAX_DEPTH = 1024;

    private const string HTML_WHITESPACE = " \t\n\f\r";

    private const array HEADINGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    /** Keeps context hints short in prompts; a longer heading or title is cut and ends with "…". */
    private const int MAX_CONTEXT_LENGTH = 200;

    public function __construct(
        private InlineEncoder $encoder = new InlineEncoder(),
    ) {}

    /**
     * @throws InvalidHtmlException when the markup is nested deeper than 1024 levels
     */
    public function segment(LoadedDocument $document, SkipRules $rules, TranslatableAttributes $attributes): SegmentedDocument
    {
        $context = new SegmentationContext($rules, $attributes, $this->findBlockContainers($document->root()));
        $this->visitContainer($document->root(), true, $context);

        return $context->build($document, $this->documentTitle($document));
    }

    /**
     * One linear pass in document order (no recursion): checks the nesting depth, then marks every
     * ancestor of a block element, walking backwards so descendants are marked before their ancestors.
     *
     * @return SplObjectStorage<Element, null>
     */
    private function findBlockContainers(Element $root): SplObjectStorage
    {
        $elements = [$root, ...$root->querySelectorAll('*')];

        /** @var WeakMap<Element, int> $depths */
        $depths = new WeakMap();
        foreach ($elements as $element) {
            $parent = $element->parentElement;
            $depth = $parent instanceof Element && isset($depths[$parent]) ? $depths[$parent] + 1 : 0;

            if ($depth > self::MAX_DEPTH) {
                throw new InvalidHtmlException(\sprintf('Markup is nested deeper than %d levels.', self::MAX_DEPTH));
            }

            $depths[$element] = $depth;
        }

        // Strong references on purpose: DOM wrapper objects are recreated once nothing holds them,
        // so a WeakMap would silently lose its entries after this method returns.
        /** @var SplObjectStorage<Element, null> $containers */
        $containers = new SplObjectStorage();
        foreach (array_reverse($elements) as $element) {
            $parent = $element->parentElement;

            if ($parent instanceof Element && ($containers->offsetExists($element) || $this->isBlockElement($element))) {
                $containers->offsetSet($parent);
            }
        }

        return $containers;
    }

    /**
     * A heading is passed once its own content is segmented, so a heading unit belongs to the previous section.
     * Only the outermost heading counts: a heading nested in it is part of its text, and reading the text of every
     * nested heading would make deep nesting quadratic.
     */
    private function visitContainer(Element $container, bool $parentTranslates, SegmentationContext $context): void
    {
        if (!$this->isHeading($container) || $context->insideHeading) {
            $this->visitContent($container, $parentTranslates, $context);

            return;
        }

        $context->insideHeading = true;
        $this->visitContent($container, $parentTranslates, $context);
        $context->insideHeading = false;

        $context->enterSection($this->contextText($container->textContent ?? ''));
    }

    private function visitContent(Element $container, bool $parentTranslates, SegmentationContext $context): void
    {
        $translates = $context->rules->translates($container, $parentTranslates);

        // Element rules exclude content, not the element's own interface attributes (svg aria-label, textarea placeholder).
        if ($translates) {
            $this->addAttributeUnits($container, $context);
        }

        if ($context->rules->skipsSubtree($container)) {
            // Not segmented, but a heading in it (e.g. HTML inside <svg><foreignObject>) still names what follows.
            // Inside a heading, the heading's own text names the section.
            if (!$context->insideHeading) {
                $headings = $container->querySelectorAll(implode(', ', self::HEADINGS));
                if ($headings->length > 0) {
                    $context->enterSection($this->contextText($headings->item($headings->length - 1)->textContent ?? ''));
                }
            }

            return;
        }

        if ($this->isDocumentTitle($container)) {
            if ($translates) {
                $this->addTitleUnit($container, $context);
            }

            return;
        }

        if ('head' === $container->localName) {
            // Metadata content never forms runs; only the title and attributes (e.g. <meta content>) are translated.
            for ($child = $container->firstElementChild; $child instanceof Element; $child = $child->nextElementSibling) {
                $this->visitContainer($child, $translates, $context);
            }

            return;
        }

        $run = [];
        foreach ($container->childNodes as $child) {
            if ($child instanceof Element && $this->isBlockLike($child, $context)) {
                $this->flushRun($run, $translates, $context);
                $run = [];
                $this->visitContainer($child, $translates, $context);

                continue;
            }

            $run[] = $child;
        }

        $this->flushRun($run, $translates, $context);
    }

    /**
     * @param list<Node> $run
     */
    private function flushRun(array $run, bool $translates, SegmentationContext $context): void
    {
        if (!$translates) {
            // The run is not translated, but an element in it may switch translation back on.
            foreach ($run as $node) {
                if ($node instanceof Element) {
                    $this->visitContainer($node, false, $context);
                }
            }

            return;
        }

        $run = $this->trimEdges($run);
        if ([] === $run) {
            return;
        }

        // A lone element with content is visited as a container: its own skip and translate rules still apply there.
        $single = $run[0];
        if (1 === \count($run) && $single instanceof Element && $single->hasChildNodes()) {
            $this->visitContainer($single, true, $context);

            return;
        }

        $encoded = $this->encoder->encode($run, $context->rules);

        if ($encoded->hasTranslatableText) {
            $context->addRun(UnitKind::Block, $encoded->text, $encoded->inlineTags, $run, $encoded->tagNodes);
        }

        foreach ($encoded->attributeCandidates as $element) {
            $this->addAttributeUnits($element, $context);
        }
    }

    /**
     * @param list<Node> $run
     *
     * @return list<Node>
     */
    private function trimEdges(array $run): array
    {
        while ([] !== $run && $this->isIgnorableEdge($run[0])) {
            array_shift($run);
        }

        while ([] !== $run && $this->isIgnorableEdge($run[\count($run) - 1])) {
            array_pop($run);
        }

        return $run;
    }

    private function isIgnorableEdge(Node $node): bool
    {
        return $node instanceof Comment
            || ($node instanceof Text && \strlen($node->data) === strspn($node->data, self::HTML_WHITESPACE));
    }

    private function isBlockElement(Element $element): bool
    {
        return \in_array($element->localName, self::BLOCK_ELEMENTS, true);
    }

    private function isBlockLike(Element $element, SegmentationContext $context): bool
    {
        return $this->isBlockElement($element)
            || (!$context->rules->skipsSubtree($element) && $context->containsBlock($element));
    }

    private function isHeading(Element $element): bool
    {
        return \in_array($element->localName, self::HEADINGS, true);
    }

    /**
     * A fragment's root holds no <head>, so fragments have no title.
     */
    private function documentTitle(LoadedDocument $document): ?string
    {
        $title = $document->root()->querySelector('head > title');

        return $title instanceof Element ? $this->contextText($title->textContent ?? '') : null;
    }

    /**
     * Text as a reader sees it, for context hints: whitespace collapsed, capped on a character boundary; null when empty.
     */
    private function contextText(string $text): ?string
    {
        // textContent is valid UTF-8, so the replacement cannot fail.
        $text = trim(preg_replace('/[\s\p{Z}]+/u', ' ', $text) ?? $text);

        if ('' === $text) {
            return null;
        }

        return mb_strlen($text) > self::MAX_CONTEXT_LENGTH ? rtrim(mb_substr($text, 0, self::MAX_CONTEXT_LENGTH - 1)) . '…' : $text;
    }

    /**
     * A <title> elsewhere (e.g. misplaced in body content) is ordinary inline content.
     */
    private function isDocumentTitle(Element $element): bool
    {
        return 'title' === $element->localName && 'head' === $element->parentElement?->localName;
    }

    private function addTitleUnit(Element $title, SegmentationContext $context): void
    {
        $plain = $title->textContent ?? '';

        if (UnitText::hasTranslatableCharacter($plain)) {
            $context->addRun(UnitKind::Title, UnitText::escape($plain), [], [...$title->childNodes], []);
        }
    }

    private function addAttributeUnits(Element $element, SegmentationContext $context): void
    {
        foreach ($context->attributes->namesFor($element) as $name) {
            $context->addAttribute($element, $name, UnitText::escape($element->getAttribute($name) ?? ''));
        }
    }
}

<?php

declare(strict_types=1);

namespace HtmlTranslator\Html;

use Dom\Element;
use Dom\Node;
use HtmlTranslator\Domain\InlineTag;
use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Domain\UnitId;
use HtmlTranslator\Domain\UnitKind;
use SplObjectStorage;

/**
 * State of one Segmenter::segment() call: the rules, which elements contain a block (computed once),
 * and the units collected so far with their ids ("u1", … for block and title units, "a1", … for attribute units).
 *
 * @internal
 */
final class SegmentationContext
{
    /** @var list<TranslationUnit> */
    private array $units = [];

    /** @var array<string, RunAnchor|AttributeAnchor> */
    private array $anchors = [];

    private int $runCount = 0;

    private int $attributeCount = 0;

    /**
     * @param SplObjectStorage<Element, null> $blockContainers elements with a block element among their descendants
     */
    public function __construct(
        public readonly SkipRules $rules,
        public readonly TranslatableAttributes $attributes,
        private readonly SplObjectStorage $blockContainers,
    ) {}

    public function containsBlock(Element $element): bool
    {
        return $this->blockContainers->offsetExists($element);
    }

    /**
     * @param list<InlineTag> $inlineTags
     * @param list<Node> $nodes
     * @param array<int, Node> $tagNodes
     */
    public function addRun(UnitKind $kind, string $text, array $inlineTags, array $nodes, array $tagNodes): void
    {
        $id = UnitId::fromString('u' . ++$this->runCount);
        $this->units[] = TranslationUnit::fromText($id, $kind, $text, $inlineTags);
        $this->anchors[$id->toString()] = new RunAnchor($nodes, $tagNodes);
    }

    public function addAttribute(Element $element, string $attribute, string $text): void
    {
        $id = UnitId::fromString('a' . ++$this->attributeCount);
        $this->units[] = TranslationUnit::fromText($id, UnitKind::Attribute, $text);
        $this->anchors[$id->toString()] = new AttributeAnchor($element, $attribute);
    }

    public function build(LoadedDocument $document): SegmentedDocument
    {
        return new SegmentedDocument($document, $this->units, $this->anchors);
    }
}

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
 * the units collected so far with their ids ("u1", … for block and title units, "a1", … for attribute units),
 * and the section heading each unit was found under.
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

    /** Text of the last heading passed in document order; null before the first one or after one without text. */
    private ?string $sectionHeading = null;

    /** @var array<string, string> unit id → section heading */
    private array $sectionHeadings = [];

    /** True while the content of a heading is walked; headings nested in it are part of its text. */
    public bool $insideHeading = false;

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
        $this->recordSectionHeading($id);
    }

    public function addAttribute(Element $element, string $attribute, string $text): void
    {
        $id = UnitId::fromString('a' . ++$this->attributeCount);
        $this->units[] = TranslationUnit::fromText($id, UnitKind::Attribute, $text);
        $this->anchors[$id->toString()] = new AttributeAnchor($element, $attribute);
        $this->recordSectionHeading($id);
    }

    /**
     * Units added from now on belong to the section of this heading.
     */
    public function enterSection(?string $heading): void
    {
        $this->sectionHeading = $heading;
    }

    public function build(LoadedDocument $document, ?string $title): SegmentedDocument
    {
        return new SegmentedDocument($document, $this->units, $this->anchors, $title, $this->sectionHeadings);
    }

    private function recordSectionHeading(UnitId $id): void
    {
        if (null !== $this->sectionHeading) {
            $this->sectionHeadings[$id->toString()] = $this->sectionHeading;
        }
    }
}

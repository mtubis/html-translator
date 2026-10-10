<?php

declare(strict_types=1);

namespace HtmlTranslator\Html;

use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Domain\UnitId;
use HtmlTranslator\Domain\UnitKind;
use HtmlTranslator\Exception\InvalidArgumentException;

/**
 * Units of a document in document order, plus where each one lives in the DOM (ADR-0007).
 *
 * Contract for reassembly: an attribute unit of an inline element points at the same node as the
 * pseudo-tag of its run (RunAnchor::$tags), so attribute units must be applied before runs are
 * replaced with clones of their tag nodes (paired tags shallow, void and opaque tags deep).
 */
final readonly class SegmentedDocument
{
    /**
     * @param list<TranslationUnit> $units
     * @param array<string, RunAnchor|AttributeAnchor> $anchors unit id → anchor
     *
     * @throws InvalidArgumentException when units and anchors do not match one to one, or an anchor has the wrong type
     */
    public function __construct(
        private LoadedDocument $document,
        private array $units,
        private array $anchors,
    ) {
        $unitIds = [];
        foreach ($units as $unit) {
            $id = $unit->id->toString();
            $unitIds[] = $id;
            $anchor = $anchors[$id] ?? throw new InvalidArgumentException(\sprintf('Unit "%s" has no anchor.', $id));

            if ((UnitKind::Attribute === $unit->kind) !== $anchor instanceof AttributeAnchor) {
                throw new InvalidArgumentException(\sprintf('Unit "%s" of kind %s has an anchor of the wrong type.', $id, $unit->kind->name));
            }
        }

        foreach (array_diff(array_keys($anchors), $unitIds) as $id) {
            throw new InvalidArgumentException(\sprintf('Anchor "%s" belongs to no unit.', $id));
        }
    }

    public function document(): LoadedDocument
    {
        return $this->document;
    }

    /**
     * @return list<TranslationUnit>
     */
    public function units(): array
    {
        return $this->units;
    }

    /**
     * @throws InvalidArgumentException when no unit has this id
     */
    public function anchorFor(UnitId $id): RunAnchor|AttributeAnchor
    {
        return $this->anchors[$id->toString()] ?? throw new InvalidArgumentException(\sprintf('Unknown unit id "%s".', $id->toString()));
    }
}

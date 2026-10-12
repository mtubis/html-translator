<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider;

/**
 * Document context hints for the units of one batch (ARCHITECTURE §6.3): the page title and the section heading
 * each unit belongs to, as plain source-language text. They help with short ambiguous texts such as button labels;
 * they are not translated.
 */
final readonly class BatchContext
{
    /**
     * Built only by the named constructors, so headings are distinct, non-empty and each used by a unit.
     *
     * @param list<string> $headings distinct section headings of the batch's units, in order of first use
     * @param array<string, int> $unitHeadings unit id → index into $headings; units without a heading are left out
     */
    private function __construct(
        public ?string $title,
        public array $headings,
        public array $unitHeadings,
    ) {}

    public static function none(): self
    {
        return new self(null, [], []);
    }

    /**
     * @param ?string $title page title; an empty one counts as none
     * @param array<string, ?string> $headingByUnit unit id → its section heading, in batch order; an empty one counts as none
     */
    public static function forUnits(?string $title, array $headingByUnit): self
    {
        $headings = [];
        $unitHeadings = [];
        foreach ($headingByUnit as $id => $heading) {
            if (null === $heading || '' === $heading) {
                continue;
            }

            $index = array_search($heading, $headings, true);
            if (false === $index) {
                $index = \count($headings);
                $headings[] = $heading;
            }

            $unitHeadings[$id] = $index;
        }

        return new self('' === $title ? null : $title, $headings, $unitHeadings);
    }

    /**
     * The context for other units, each taking the heading of a unit of this context: the core builds one context
     * for the whole document and selects from it per batch.
     *
     * @param array<string, string> $sources unit id → id of the unit in this context whose heading it gets
     *                                       (itself, or the unit a text node of the fallback comes from)
     */
    public function select(array $sources): self
    {
        $headingByUnit = [];
        foreach ($sources as $id => $source) {
            $index = $this->unitHeadings[$source] ?? null;
            // The constructor guarantees the index exists; ?? only satisfies the type of a list lookup.
            $headingByUnit[$id] = null === $index ? null : $this->headings[$index] ?? null;
        }

        return self::forUnits($this->title, $headingByUnit);
    }

    public function isEmpty(): bool
    {
        return null === $this->title && [] === $this->headings;
    }
}

<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider;

use HtmlTranslator\Exception\InvalidArgumentException;

/**
 * Document context hints for the units of one batch (ARCHITECTURE §6.3): the page title and the section heading
 * each unit belongs to, as plain source-language text. They help with short ambiguous texts such as button labels;
 * they are not translated.
 */
final readonly class BatchContext
{
    /**
     * @param list<string> $headings distinct section headings of the batch's units, in order of first use
     * @param array<string, int> $unitHeadings unit id → index into $headings; units without a heading are left out
     *
     * @throws InvalidArgumentException when an index points outside $headings or a heading belongs to no unit
     */
    public function __construct(
        public ?string $title,
        public array $headings,
        public array $unitHeadings,
    ) {
        foreach ($unitHeadings as $id => $index) {
            if (!isset($headings[$index])) {
                throw new InvalidArgumentException(\sprintf('Unit "%s" refers to heading %d, which does not exist.', $id, $index));
            }
        }

        foreach (array_diff_key($headings, array_flip($unitHeadings)) as $heading) {
            throw new InvalidArgumentException(\sprintf('The heading "%s" belongs to no unit.', $heading));
        }
    }

    public static function none(): self
    {
        return new self(null, [], []);
    }

    /**
     * @param array<string, ?string> $headingByUnit unit id → its section heading, in batch order
     */
    public static function forUnits(?string $title, array $headingByUnit): self
    {
        $headings = [];
        $unitHeadings = [];
        foreach ($headingByUnit as $id => $heading) {
            if (null === $heading) {
                continue;
            }

            $index = array_search($heading, $headings, true);
            if (false === $index) {
                $index = \count($headings);
                $headings[] = $heading;
            }

            $unitHeadings[$id] = $index;
        }

        return new self($title, $headings, $unitHeadings);
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

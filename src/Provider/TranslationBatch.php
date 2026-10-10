<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider;

use HtmlTranslator\Domain\LocalePair;
use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Exception\InvalidArgumentException;

/**
 * Units sent to a provider in one call; on a corrective retry, corrections say what was wrong before.
 * Built by the core, read by providers; it will gain properties (translation profile, document context)
 * in later versions, with defaults.
 */
final readonly class TranslationBatch
{
    /**
     * @param list<TranslationUnit> $units
     * @param array<string, Correction> $corrections unit id → feedback, only for units of this batch
     *
     * @throws InvalidArgumentException when there are no units, an id repeats, or a correction belongs to no unit of the batch
     */
    public function __construct(
        public LocalePair $locales,
        public array $units,
        public array $corrections = [],
    ) {
        if ([] === $units) {
            throw new InvalidArgumentException('A translation batch needs at least one unit.');
        }

        $ids = array_map(static fn(TranslationUnit $unit): string => $unit->id->toString(), $units);
        foreach (array_diff_assoc($ids, array_unique($ids)) as $id) {
            throw new InvalidArgumentException(\sprintf('Unit "%s" appears more than once in the batch.', $id));
        }

        foreach (array_diff(array_keys($corrections), $ids) as $id) {
            throw new InvalidArgumentException(\sprintf('Correction for unit "%s", which is not in the batch.', $id));
        }
    }
}

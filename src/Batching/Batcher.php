<?php

declare(strict_types=1);

namespace HtmlTranslator\Batching;

use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Exception\InvalidArgumentException;

/**
 * Groups units into batches by an approximate token budget and a unit cap (ARCHITECTURE §6.3, ADR-0015).
 *
 * The budget covers the unit texts and their JSON envelope in the prompt, not the instructions or the answer.
 * Order is preserved and units are never split; a unit over the budget gets a batch of its own.
 */
final readonly class Batcher
{
    /**
     * Tokens of a unit's envelope in the prompt: `{"id":"u12","kind":"block","text":""},` is 14 in o200k_base, 16 with
     * a section heading index (`"heading":12`); the rest is headroom for escaped quotes and backslashes.
     */
    public const int UNIT_OVERHEAD = 20;

    public function __construct(
        private TokenEstimatorInterface $estimator = new CharacterTokenEstimator(),
    ) {}

    /**
     * @param list<TranslationUnit> $units
     *
     * @throws InvalidArgumentException when the budget or the unit cap is below 1
     *
     * @return list<non-empty-list<TranslationUnit>>
     */
    public function batches(array $units, int $tokenBudget, int $maxUnits): array
    {
        if ($tokenBudget < 1) {
            throw new InvalidArgumentException(\sprintf('Token budget must be at least 1, got %d.', $tokenBudget));
        }

        if ($maxUnits < 1) {
            throw new InvalidArgumentException(\sprintf('Units per batch must be at least 1, got %d.', $maxUnits));
        }

        $batches = [];
        $batch = [];
        $tokens = 0;

        foreach ($units as $unit) {
            $cost = $this->estimator->estimate($unit->text) + self::UNIT_OVERHEAD;

            if ([] !== $batch && ($tokens + $cost > $tokenBudget || \count($batch) >= $maxUnits)) {
                $batches[] = $batch;
                $batch = [];
                $tokens = 0;
            }

            $batch[] = $unit;
            $tokens += $cost;
        }

        if ([] !== $batch) {
            $batches[] = $batch;
        }

        return $batches;
    }
}

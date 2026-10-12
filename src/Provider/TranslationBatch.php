<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider;

use HtmlTranslator\Domain\LocalePair;
use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Profile\TranslationProfile;

/**
 * Units sent to a provider in one call; on a corrective retry, corrections say what was wrong before.
 * Built by the core, read by providers; new properties come with defaults.
 */
final readonly class TranslationBatch
{
    /** How the units are translated; providers that cannot be steered ignore it. */
    public TranslationProfile $profile;

    /** Page title and section headings of the units, for understanding them; never translated. */
    public BatchContext $context;

    /**
     * @param list<TranslationUnit> $units
     * @param array<string, Correction> $corrections unit id → feedback, only for units of this batch
     * @param TranslationProfile|null $profile null means TranslationProfile::default()
     * @param BatchContext|null $context null means BatchContext::none()
     *
     * @throws InvalidArgumentException when there are no units, an id repeats, or a correction or context heading
     *                                  belongs to no unit of the batch
     */
    public function __construct(
        public LocalePair $locales,
        public array $units,
        public array $corrections = [],
        ?TranslationProfile $profile = null,
        ?BatchContext $context = null,
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

        $this->profile = $profile ?? TranslationProfile::default();
        $this->context = $context ?? BatchContext::none();

        foreach (array_diff(array_keys($this->context->unitHeadings), $ids) as $id) {
            throw new InvalidArgumentException(\sprintf('Context heading for unit "%s", which is not in the batch.', $id));
        }
    }
}

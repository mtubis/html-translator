<?php

declare(strict_types=1);

namespace HtmlTranslator;

final readonly class TranslationStats
{
    /**
     * @param int $batches batches sent to the provider, corrective retries not counted
     * @param int $retries units sent again in corrective retries (one unit retried twice counts 2)
     * @param int|null $inputTokens prompt tokens of all provider calls (corrective retries and the text-node fallback
     *                              included), null while no call reported any; a lower bound when some calls did not
     *                              report it, and a provider call that ends in a ProviderException adds nothing, also
     *                              not for calls that answered before it
     * @param int|null $outputTokens answer tokens of the same calls, with the same rules
     * @param int $cacheHits units taken from the unit cache instead of the provider; they are part of $units
     */
    public function __construct(
        public int $units,
        public int $batches,
        public int $retries,
        public float $durationSeconds,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public int $cacheHits = 0,
    ) {}
}

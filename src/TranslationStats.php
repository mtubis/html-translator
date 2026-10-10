<?php

declare(strict_types=1);

namespace HtmlTranslator;

final readonly class TranslationStats
{
    /**
     * @param int $batches batches sent to the provider, corrective retries not counted
     * @param int $retries units sent again in corrective retries (one unit retried twice counts 2)
     */
    public function __construct(
        public int $units,
        public int $batches,
        public int $retries,
        public float $durationSeconds,
    ) {}
}

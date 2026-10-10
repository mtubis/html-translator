<?php

declare(strict_types=1);

namespace HtmlTranslator;

/**
 * Passed to TranslationOptions::$onProgress after each batch, corrective retries and fallback included.
 */
final readonly class Progress
{
    public function __construct(
        public int $batchesDone,
        public int $batchesTotal,
        public int $unitsDone,
        public int $unitsTotal,
    ) {}
}

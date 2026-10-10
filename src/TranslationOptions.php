<?php

declare(strict_types=1);

namespace HtmlTranslator;

use Closure;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Exception\InvalidArgumentException;

final readonly class TranslationOptions
{
    /** @var non-negative-int how often a unit failing validation is sent again with feedback */
    public int $maxCorrectiveRetries;

    /** @var positive-int units per provider call (a token-based batcher replaces this in 0.4) */
    public int $batchSize;

    /**
     * @param Locale|null $source null lets the model detect the source language
     * @param (Closure(Progress): void)|null $onProgress called after each batch
     *
     * @throws InvalidArgumentException when retries are negative or the batch size is below 1
     */
    public function __construct(
        public ?Locale $source = null,
        public FallbackStrategy $fallback = FallbackStrategy::KeepOriginal,
        int $maxCorrectiveRetries = 2,
        int $batchSize = 20,
        public ?Closure $onProgress = null,
    ) {
        if ($maxCorrectiveRetries < 0) {
            throw new InvalidArgumentException(\sprintf('Corrective retries cannot be negative, got %d.', $maxCorrectiveRetries));
        }

        if ($batchSize < 1) {
            throw new InvalidArgumentException(\sprintf('Batch size must be at least 1, got %d.', $batchSize));
        }

        $this->maxCorrectiveRetries = $maxCorrectiveRetries;
        $this->batchSize = $batchSize;
    }
}

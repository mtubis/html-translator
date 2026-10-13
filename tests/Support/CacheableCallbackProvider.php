<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Support;

use Closure;
use HtmlTranslator\Provider\BatchResult;
use HtmlTranslator\Provider\CacheableProviderInterface;
use HtmlTranslator\Provider\TranslationBatch;
use HtmlTranslator\Provider\TranslationProviderInterface;

/**
 * Test double: a cacheable provider that answers through a callback (or another provider) and records the batches
 * it received.
 */
final class CacheableCallbackProvider implements CacheableProviderInterface
{
    /** @var list<TranslationBatch> */
    public array $batches = [];

    /**
     * @param Closure(TranslationBatch): BatchResult $answer
     */
    public function __construct(
        private readonly Closure $answer,
        private readonly string $cacheId = 'callback',
    ) {}

    public static function delegatingTo(TranslationProviderInterface $provider, string $cacheId = 'callback'): self
    {
        return new self($provider->translate(...), $cacheId);
    }

    public function translate(TranslationBatch $batch): BatchResult
    {
        $this->batches[] = $batch;

        return ($this->answer)($batch);
    }

    public function cacheId(): string
    {
        return $this->cacheId;
    }

    /**
     * @return list<string> ids of every unit sent, in order, across all batches
     */
    public function sentUnitIds(): array
    {
        $ids = [];
        foreach ($this->batches as $batch) {
            foreach ($batch->units as $unit) {
                $ids[] = $unit->id->toString();
            }
        }

        return $ids;
    }
}

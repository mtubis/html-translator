<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Support;

use Closure;
use HtmlTranslator\Provider\BatchResult;
use HtmlTranslator\Provider\TranslationBatch;
use HtmlTranslator\Provider\TranslationProviderInterface;

/**
 * Test double: answers every batch through a callback and records the batches it received.
 */
final class CallbackProvider implements TranslationProviderInterface
{
    /** @var list<TranslationBatch> */
    public array $batches = [];

    /**
     * @param Closure(TranslationBatch): BatchResult $answer
     */
    public function __construct(
        private readonly Closure $answer,
    ) {}

    public function translate(TranslationBatch $batch): BatchResult
    {
        $this->batches[] = $batch;

        return ($this->answer)($batch);
    }
}

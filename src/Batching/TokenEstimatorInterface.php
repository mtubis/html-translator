<?php

declare(strict_types=1);

namespace HtmlTranslator\Batching;

/**
 * Estimates how many tokens a model needs for a text (ADR-0015). A port: vendors offer exact counting endpoints.
 */
interface TokenEstimatorInterface
{
    /**
     * @return non-negative-int
     */
    public function estimate(string $text): int;
}

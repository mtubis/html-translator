<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm\Testing;

use HtmlTranslator\Provider\Llm\Bridge\SleeperInterface;

/**
 * Records the delays instead of sleeping, so tests of an adapter that uses Retrier run instantly.
 */
final class FakeSleeper implements SleeperInterface
{
    /** @var list<int> milliseconds, in order */
    public private(set) array $delays = [];

    public function sleep(int $milliseconds): void
    {
        $this->delays[] = $milliseconds;
    }
}

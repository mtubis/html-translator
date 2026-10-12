<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Support;

use HtmlTranslator\Provider\Llm\Bridge\Sleeper;

/**
 * Records the delays instead of sleeping, so retry tests run instantly.
 */
final class FakeSleeper implements Sleeper
{
    /** @var list<int> milliseconds, in order */
    public private(set) array $delays = [];

    public function sleep(int $milliseconds): void
    {
        $this->delays[] = $milliseconds;
    }
}

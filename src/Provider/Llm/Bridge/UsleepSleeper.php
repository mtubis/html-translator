<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm\Bridge;

/**
 * Blocks the process for the given time.
 */
final readonly class UsleepSleeper implements SleeperInterface
{
    public function sleep(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}

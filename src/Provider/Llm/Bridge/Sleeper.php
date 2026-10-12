<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm\Bridge;

/**
 * Waits between retries. A port, so tests and event loops can replace the blocking default (UsleepSleeper).
 */
interface Sleeper
{
    public function sleep(int $milliseconds): void;
}

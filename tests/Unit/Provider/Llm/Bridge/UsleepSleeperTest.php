<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Provider\Llm\Bridge;

use HtmlTranslator\Provider\Llm\Bridge\UsleepSleeper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UsleepSleeper::class)]
final class UsleepSleeperTest extends TestCase
{
    #[Test]
    public function it_blocks_for_the_given_time(): void
    {
        $started = hrtime(true);

        new UsleepSleeper()->sleep(5);

        self::assertGreaterThanOrEqual(5_000_000, hrtime(true) - $started);
    }

    #[Test]
    public function it_returns_at_once_for_no_time(): void
    {
        $started = hrtime(true);

        new UsleepSleeper()->sleep(0);
        new UsleepSleeper()->sleep(-10);

        self::assertLessThan(5_000_000, hrtime(true) - $started);
    }
}

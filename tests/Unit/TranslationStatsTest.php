<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit;

use HtmlTranslator\TranslationStats;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TranslationStats::class)]
final class TranslationStatsTest extends TestCase
{
    #[Test]
    public function it_reports_no_tokens_and_no_cache_hits_unless_given(): void
    {
        $stats = new TranslationStats(units: 3, batches: 1, retries: 0, durationSeconds: 0.5);

        self::assertNull($stats->inputTokens);
        self::assertNull($stats->outputTokens);
        self::assertSame(0, $stats->cacheHits);
    }
}

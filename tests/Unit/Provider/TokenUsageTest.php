<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Provider;

use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Provider\TokenUsage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TokenUsage::class)]
#[UsesClass(InvalidArgumentException::class)]
final class TokenUsageTest extends TestCase
{
    #[Test]
    public function it_holds_input_and_output_tokens(): void
    {
        $usage = new TokenUsage(120, 45);

        self::assertSame(120, $usage->inputTokens);
        self::assertSame(45, $usage->outputTokens);
    }

    #[Test]
    public function it_accepts_usage_with_only_one_side_known(): void
    {
        self::assertNull(new TokenUsage(120, null)->outputTokens);
        self::assertNull(new TokenUsage(null, 45)->inputTokens);
    }

    #[Test]
    public function it_accepts_zero(): void
    {
        self::assertSame(0, new TokenUsage(0, 0)->inputTokens);
    }

    /**
     * @return iterable<string, array{?int, ?int}>
     */
    public static function negativeCounts(): iterable
    {
        yield 'input' => [-1, 0];
        yield 'output' => [0, -1];
    }

    #[Test]
    #[DataProvider('negativeCounts')]
    public function it_rejects_negative_counts(?int $input, ?int $output): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TokenUsage($input, $output);
    }

    #[Test]
    public function it_rejects_usage_without_any_count(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TokenUsage(null, null);
    }

    #[Test]
    public function it_reads_reported_usage_as_null_when_neither_count_is_known(): void
    {
        self::assertNull(TokenUsage::reported(null, null));
        self::assertEquals(new TokenUsage(120, null), TokenUsage::reported(120, null));
    }

    /**
     * @return iterable<string, array{?TokenUsage, ?TokenUsage, ?TokenUsage}>
     */
    public static function sums(): iterable
    {
        yield 'both unknown' => [null, null, null];
        yield 'left unknown' => [null, new TokenUsage(10, 2), new TokenUsage(10, 2)];
        yield 'right unknown' => [new TokenUsage(10, 2), null, new TokenUsage(10, 2)];
        yield 'both known' => [new TokenUsage(10, 2), new TokenUsage(5, 3), new TokenUsage(15, 5)];
        yield 'one side unknown in one of them' => [new TokenUsage(10, null), new TokenUsage(5, 3), new TokenUsage(15, 3)];
        yield 'different sides unknown' => [new TokenUsage(10, null), new TokenUsage(null, 3), new TokenUsage(10, 3)];
    }

    #[Test]
    #[DataProvider('sums')]
    public function it_sums_usage_treating_null_as_unknown_not_zero(?TokenUsage $left, ?TokenUsage $right, ?TokenUsage $expected): void
    {
        self::assertEquals($expected, TokenUsage::sum($left, $right));
    }
}

<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Provider\Llm\Bridge;

use Closure;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Provider\Llm\Bridge\RetryPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

#[CoversClass(RetryPolicy::class)]
#[UsesClass(InvalidArgumentException::class)]
final class RetryPolicyTest extends TestCase
{
    #[Test]
    public function it_has_the_documented_defaults(): void
    {
        $policy = RetryPolicy::default();

        self::assertSame(3, $policy->maxAttempts);
        self::assertSame(1000, $policy->baseDelayMilliseconds);
        self::assertSame(30_000, $policy->maxDelayMilliseconds);
        self::assertSame([408, 429, 500, 502, 503, 504, 529], $policy->retryableStatuses);
        self::assertTrue($policy->retryNetworkErrors);
        self::assertEquals(new RetryPolicy(), RetryPolicy::default());
    }

    #[Test]
    public function it_can_switch_retries_off(): void
    {
        self::assertSame(1, RetryPolicy::none()->maxAttempts);
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function statuses(): iterable
    {
        foreach ([408, 429, 500, 502, 503, 504, 529] as $status) {
            yield (string) $status => [$status, true];
        }

        foreach ([200, 400, 401, 403, 404, 413, 422, 501] as $status) {
            yield (string) $status => [$status, false];
        }
    }

    #[Test]
    #[DataProvider('statuses')]
    public function it_retries_only_transient_statuses(int $status, bool $retryable): void
    {
        self::assertSame($retryable, RetryPolicy::default()->retries($status));
    }

    #[Test]
    public function it_grows_the_backoff_exponentially_with_jitter_up_to_the_maximum(): void
    {
        $policy = new RetryPolicy(maxAttempts: 10, baseDelayMilliseconds: 1000, maxDelayMilliseconds: 8000);
        $randomizer = new Randomizer(new Mt19937(42));

        foreach ([1 => 1000, 2 => 2000, 3 => 4000, 4 => 8000, 5 => 8000, 9 => 8000] as $retry => $ceiling) {
            for ($sample = 0; $sample < 20; ++$sample) {
                $delay = $policy->backoffMilliseconds($retry, $randomizer);
                self::assertGreaterThanOrEqual(intdiv($ceiling, 2), $delay, \sprintf('Retry %d', $retry));
                self::assertLessThanOrEqual($ceiling, $delay, \sprintf('Retry %d', $retry));
            }
        }
    }

    #[Test]
    public function it_varies_the_backoff(): void
    {
        $policy = RetryPolicy::default();
        $randomizer = new Randomizer(new Mt19937(7));

        $delays = array_map(static fn(): int => $policy->backoffMilliseconds(2, $randomizer), range(1, 20));

        self::assertGreaterThan(1, \count(array_unique($delays)), 'Jitter spreads simultaneous retries.');
    }

    #[Test]
    public function it_does_not_wait_with_a_zero_base_delay(): void
    {
        self::assertSame(0, new RetryPolicy(baseDelayMilliseconds: 0, maxDelayMilliseconds: 0)->backoffMilliseconds(3, new Randomizer(new Mt19937(1))));
    }

    /**
     * @return iterable<string, array{Closure(): RetryPolicy}>
     */
    public static function invalidPolicies(): iterable
    {
        yield 'no attempt' => [static fn(): RetryPolicy => new RetryPolicy(maxAttempts: 0)];
        yield 'negative base delay' => [static fn(): RetryPolicy => new RetryPolicy(baseDelayMilliseconds: -1)];
        yield 'maximum below the base' => [static fn(): RetryPolicy => new RetryPolicy(baseDelayMilliseconds: 2000, maxDelayMilliseconds: 1000)];
        yield 'success status as retryable' => [static fn(): RetryPolicy => new RetryPolicy(retryableStatuses: [200])];
        yield 'status outside HTTP' => [static fn(): RetryPolicy => new RetryPolicy(retryableStatuses: [700])];
    }

    /**
     * @param Closure(): RetryPolicy $create
     */
    #[Test]
    #[DataProvider('invalidPolicies')]
    public function it_rejects_invalid_settings(Closure $create): void
    {
        $this->expectException(InvalidArgumentException::class);

        $create();
    }
}

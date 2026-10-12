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
use ReflectionClass;

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
    public function it_treats_retry_zero_like_the_first_retry(): void
    {
        $delay = new RetryPolicy(baseDelayMilliseconds: 1000)->backoffMilliseconds(0, new Randomizer(new Mt19937(5)));

        self::assertGreaterThanOrEqual(500, $delay);
        self::assertLessThanOrEqual(1000, $delay);
    }

    #[Test]
    public function it_never_waits_less_than_half_the_backoff(): void
    {
        $policy = new RetryPolicy(baseDelayMilliseconds: 2, maxDelayMilliseconds: 2);
        $randomizer = new Randomizer(new Mt19937(11));

        for ($sample = 0; $sample < 200; ++$sample) {
            $delay = $policy->backoffMilliseconds(1, $randomizer);
            self::assertGreaterThanOrEqual(1, $delay);
            self::assertLessThanOrEqual(2, $delay);
        }
    }

    #[Test]
    public function it_accepts_the_bounds_of_the_error_statuses(): void
    {
        self::assertSame([400, 599], new RetryPolicy(retryableStatuses: [400, 599])->retryableStatuses);
    }

    #[Test]
    public function it_accepts_the_bounds_of_the_attempts_and_the_delay(): void
    {
        $policy = new RetryPolicy(maxAttempts: RetryPolicy::MAX_ATTEMPTS, maxDelayMilliseconds: RetryPolicy::MAX_DELAY_MILLISECONDS);

        self::assertSame(10, $policy->maxAttempts);
        self::assertSame(3_600_000, $policy->maxDelayMilliseconds);
    }

    #[Test]
    public function it_lists_each_status_once(): void
    {
        self::assertSame([429, 503], new RetryPolicy(retryableStatuses: [429, 429, 503])->retryableStatuses);
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
        yield 'redirect status' => [static fn(): RetryPolicy => new RetryPolicy(retryableStatuses: [399])];
        // Reflection, because a string status is what untyped configuration delivers, not what the signature allows.
        yield 'status as a string' => [static fn(): RetryPolicy => new ReflectionClass(RetryPolicy::class)->newInstanceArgs(['retryableStatuses' => ['429']])];
        yield 'too many attempts' => [static fn(): RetryPolicy => new RetryPolicy(maxAttempts: 11)];
        yield 'maximum delay over an hour' => [static fn(): RetryPolicy => new RetryPolicy(maxDelayMilliseconds: 3_600_001)];
        yield 'status just above the errors' => [static fn(): RetryPolicy => new RetryPolicy(retryableStatuses: [600])];
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

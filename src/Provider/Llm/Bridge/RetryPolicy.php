<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm\Bridge;

use HtmlTranslator\Exception\InvalidArgumentException;
use Random\Randomizer;

/**
 * When and how long a chat model adapter retries a transient failure (ARCHITECTURE §6.8): rate limits, overload,
 * server errors and network errors. Permanent errors (400, 401, 404, …) are never retried. A 5xx or a network error may
 * come after the API already ran (and billed) the request, so a retry can cost twice; see docs/providers.md.
 */
final readonly class RetryPolicy
{
    /** @var list<int> */
    public array $retryableStatuses;

    public const array DEFAULT_RETRYABLE_STATUSES = [408, 429, 500, 502, 503, 504, 529];

    public const int MAX_ATTEMPTS = 10;

    /** One hour; a longer wait is better left to the caller (and would overflow microseconds in some sleepers). */
    public const int MAX_DELAY_MILLISECONDS = 3_600_000;

    /** The backoff stops doubling here; the maximum delay caps it long before. */
    private const int MAX_EXPONENT = 30;

    /**
     * @param int $maxAttempts calls in total, the first included; 1 disables retries
     * @param int $baseDelayMilliseconds wait before the first retry (halved to doubled by jitter), doubling per retry
     * @param int $maxDelayMilliseconds upper bound of a single wait, also for Retry-After
     * @param list<int> $retryableStatuses HTTP error statuses (400–599) worth another attempt
     * @param bool $retryNetworkErrors retry PSR-18 network errors (no response at all)
     *
     * @throws InvalidArgumentException when a setting is out of range
     */
    public function __construct(
        public int $maxAttempts = 3,
        public int $baseDelayMilliseconds = 1000,
        public int $maxDelayMilliseconds = 30_000,
        array $retryableStatuses = self::DEFAULT_RETRYABLE_STATUSES,
        public bool $retryNetworkErrors = true,
    ) {
        if ($maxAttempts < 1 || $maxAttempts > self::MAX_ATTEMPTS) {
            throw new InvalidArgumentException(\sprintf('A retry policy needs 1 to %d attempts, got %d.', self::MAX_ATTEMPTS, $maxAttempts));
        }

        if ($baseDelayMilliseconds < 0 || $maxDelayMilliseconds < $baseDelayMilliseconds || $maxDelayMilliseconds > self::MAX_DELAY_MILLISECONDS) {
            throw new InvalidArgumentException(\sprintf('Retry delays must satisfy 0 <= base (%d) <= maximum (%d) <= %d.', $baseDelayMilliseconds, $maxDelayMilliseconds, self::MAX_DELAY_MILLISECONDS));
        }

        foreach ($retryableStatuses as $status) {
            // Checked at runtime too: a status read from configuration as the string "429" would never match.
            if (!\is_int($status) || $status < 400 || $status > 599) {
                throw new InvalidArgumentException(\sprintf('%s is not an error status worth retrying (an integer from 400 to 599).', var_export($status, true)));
            }
        }

        $this->retryableStatuses = array_values(array_unique($retryableStatuses));
    }

    public static function default(): self
    {
        return new self();
    }

    /**
     * One attempt only.
     */
    public static function none(): self
    {
        return new self(maxAttempts: 1);
    }

    public function retries(int $status): bool
    {
        return \in_array($status, $this->retryableStatuses, true);
    }

    /**
     * @internal for Retrier
     *
     * Exponential backoff with "equal jitter": between half and all of base · 2^(retry − 1), capped at the maximum.
     * Jitter spreads the retries of clients that failed at the same moment.
     *
     * @param int $retry 1 for the first retry
     */
    public function backoffMilliseconds(int $retry, Randomizer $randomizer): int
    {
        $exponent = min(max($retry - 1, 0), self::MAX_EXPONENT);
        $delay = min($this->maxDelayMilliseconds, $this->baseDelayMilliseconds * (1 << $exponent));
        $half = intdiv($delay, 2);

        return $half + $randomizer->getInt(0, $delay - $half);
    }
}

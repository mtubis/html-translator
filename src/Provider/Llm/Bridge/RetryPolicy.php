<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm\Bridge;

use HtmlTranslator\Exception\InvalidArgumentException;
use Random\Randomizer;

/**
 * When and how long a chat model adapter retries a transient failure (ARCHITECTURE §6.8): rate limits, overload,
 * server errors and network errors. Permanent errors (400, 401, 404, …) are never retried.
 */
final readonly class RetryPolicy
{
    public const array DEFAULT_RETRYABLE_STATUSES = [408, 429, 500, 502, 503, 504, 529];

    /** The backoff stops doubling here; the maximum delay caps it long before. */
    private const int MAX_EXPONENT = 30;

    /**
     * @param int $maxAttempts calls in total, the first included; 1 disables retries
     * @param int $baseDelayMilliseconds wait before the first retry (halved to doubled by jitter), doubling per retry
     * @param int $maxDelayMilliseconds upper bound of a single wait, also for Retry-After
     * @param list<int> $retryableStatuses HTTP statuses worth another attempt
     * @param bool $retryNetworkErrors retry PSR-18 network errors (no response at all)
     *
     * @throws InvalidArgumentException when a setting is out of range
     */
    public function __construct(
        public int $maxAttempts = 3,
        public int $baseDelayMilliseconds = 1000,
        public int $maxDelayMilliseconds = 30_000,
        public array $retryableStatuses = self::DEFAULT_RETRYABLE_STATUSES,
        public bool $retryNetworkErrors = true,
    ) {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException(\sprintf('A retry policy needs at least 1 attempt, got %d.', $maxAttempts));
        }

        if ($baseDelayMilliseconds < 0 || $maxDelayMilliseconds < $baseDelayMilliseconds) {
            throw new InvalidArgumentException(\sprintf('Retry delays must satisfy 0 <= base (%d) <= maximum (%d).', $baseDelayMilliseconds, $maxDelayMilliseconds));
        }

        foreach ($retryableStatuses as $status) {
            if ($status < 300 || $status > 599) {
                throw new InvalidArgumentException(\sprintf('%d is not an error status worth retrying.', $status));
            }
        }
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

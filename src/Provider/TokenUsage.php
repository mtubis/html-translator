<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider;

use HtmlTranslator\Exception\InvalidArgumentException;

/**
 * Tokens a provider used, as its vendor reported them; a count is null when the vendor did not report it.
 * Usage nobody reported at all is not a TokenUsage but null, so "unknown" is never mistaken for 0.
 */
final readonly class TokenUsage
{
    /**
     * @throws InvalidArgumentException when a count is negative or neither count is known
     */
    public function __construct(
        public ?int $inputTokens,
        public ?int $outputTokens,
    ) {
        if (null === $inputTokens && null === $outputTokens) {
            throw new InvalidArgumentException('Token usage needs at least one count; use null for usage nobody reported.');
        }

        if ((null !== $inputTokens && $inputTokens < 0) || (null !== $outputTokens && $outputTokens < 0)) {
            throw new InvalidArgumentException('Token counts cannot be negative.');
        }
    }

    /**
     * The usage a vendor reported, or null when it reported neither count.
     *
     * @throws InvalidArgumentException when a count is negative
     */
    public static function reported(?int $inputTokens, ?int $outputTokens): ?self
    {
        return null === $inputTokens && null === $outputTokens ? null : new self($inputTokens, $outputTokens);
    }

    /**
     * Unknown counts add nothing, so a sum stays null until some usage reported that count, and a summed count is
     * only a lower bound when some of the usages did not report it.
     */
    public static function sum(?self $left, ?self $right): ?self
    {
        if (!$left instanceof self || !$right instanceof self) {
            return $left ?? $right;
        }

        return new self(self::add($left->inputTokens, $right->inputTokens), self::add($left->outputTokens, $right->outputTokens));
    }

    private static function add(?int $left, ?int $right): ?int
    {
        return null === $left || null === $right ? $left ?? $right : $left + $right;
    }
}

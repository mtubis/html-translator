<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Support;

use DateInterval;
use DateTimeInterface;
use Psr\Cache\CacheItemInterface;

/**
 * Item of InMemoryCachePool; expiry is accepted and ignored.
 */
final class InMemoryCacheItem implements CacheItemInterface
{
    private function __construct(
        private readonly string $key,
        private mixed $value,
        private readonly bool $hit,
    ) {}

    public static function hit(string $key, mixed $value): self
    {
        return new self($key, $value, true);
    }

    public static function miss(string $key): self
    {
        return new self($key, null, false);
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->value;
    }

    public function isHit(): bool
    {
        return $this->hit;
    }

    public function set(mixed $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function expiresAt(?DateTimeInterface $expiration): static
    {
        return $this;
    }

    public function expiresAfter(DateInterval|int|null $time): static
    {
        return $this;
    }
}

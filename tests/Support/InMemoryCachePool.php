<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Support;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Test double: a PSR-6 pool in an array, without expiry. Values are stored as given, so tests can plant corrupt
 * entries; $saves counts items that reached the pool (save or committed saveDeferred), $commits calls of commit().
 */
final class InMemoryCachePool implements CacheItemPoolInterface
{
    /** @var array<string, mixed> */
    public array $values = [];

    public int $saves = 0;

    public int $commits = 0;

    /** @var array<string, CacheItemInterface> */
    private array $deferred = [];

    public function getItem(string $key): CacheItemInterface
    {
        return \array_key_exists($key, $this->values) ? InMemoryCacheItem::hit($key, $this->values[$key]) : InMemoryCacheItem::miss($key);
    }

    /**
     * @param array<string> $keys
     *
     * @return array<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        $items = [];
        foreach ($keys as $key) {
            $items[$key] = $this->getItem($key);
        }

        return $items;
    }

    public function hasItem(string $key): bool
    {
        return \array_key_exists($key, $this->values);
    }

    public function clear(): bool
    {
        $this->values = [];
        $this->deferred = [];

        return true;
    }

    public function deleteItem(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    /**
     * @param array<string> $keys
     */
    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            unset($this->values[$key]);
        }

        return true;
    }

    public function save(CacheItemInterface $item): bool
    {
        $this->values[$item->getKey()] = $item->get();
        ++$this->saves;

        return true;
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        $this->deferred[$item->getKey()] = $item;

        return true;
    }

    public function commit(): bool
    {
        ++$this->commits;
        foreach ($this->deferred as $item) {
            $this->save($item);
        }

        $this->deferred = [];

        return true;
    }
}

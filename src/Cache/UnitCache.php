<?php

declare(strict_types=1);

namespace HtmlTranslator\Cache;

use HtmlTranslator\Domain\LocalePair;
use HtmlTranslator\Domain\TranslationUnit;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Unit translations in a PSR-6 pool (ARCHITECTURE §6.7, ADR-0016). Values are unit text in the provider output format
 * (ADR-0008), without the unit's surrounding whitespace. Entries are read as untrusted: anything but a string is a
 * miss here, and the caller validates the structure of a hit like any provider answer. No expiry is set; configure it
 * on the pool.
 *
 * @internal used by HtmlTranslator
 */
final readonly class UnitCache
{
    public function __construct(
        private CacheItemPoolInterface $pool,
    ) {}

    /**
     * @param list<TranslationUnit> $units
     *
     * @return array<string, string> unit id → cached translation, for the units with a string entry
     */
    public function fetch(array $units, LocalePair $locales, string $profileFingerprint, string $providerId): array
    {
        $keys = [];
        foreach ($units as $unit) {
            $keys[$unit->id->toString()] = CacheKey::of($unit, $locales, $profileFingerprint, $providerId)->toString();
        }

        // PSR-6 declares getItems() as a bare iterable; key items by their own key instead of trusting the iterator.
        $items = [];
        foreach ($this->pool->getItems($keys) as $item) {
            if ($item instanceof CacheItemInterface) {
                $items[$item->getKey()] = $item;
            }
        }

        $translations = [];
        foreach ($keys as $id => $key) {
            // PSR-6: get() returns null on a miss.
            $value = ($items[$key] ?? null)?->get();
            if (\is_string($value)) {
                $translations[$id] = $value;
            }
        }

        return $translations;
    }

    /**
     * @param list<TranslationUnit> $units
     * @param array<string, string> $translations unit id → validated translation; units without one are skipped
     */
    public function store(array $units, array $translations, LocalePair $locales, string $profileFingerprint, string $providerId): void
    {
        $deferred = 0;
        foreach ($units as $unit) {
            $translation = $translations[$unit->id->toString()] ?? null;
            if (null === $translation) {
                continue;
            }

            $item = $this->pool->getItem(CacheKey::of($unit, $locales, $profileFingerprint, $providerId)->toString());
            $this->pool->saveDeferred($item->set($translation));
            ++$deferred;
        }

        // A batch without valid translations needs no round trip to a networked pool.
        if ($deferred > 0) {
            $this->pool->commit();
        }
    }
}

<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Cache;

use HtmlTranslator\Cache\CacheKey;
use HtmlTranslator\Cache\UnitCache;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Domain\LocalePair;
use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Domain\UnitId;
use HtmlTranslator\Domain\UnitKind;
use HtmlTranslator\Tests\Support\InMemoryCachePool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;

#[CoversClass(UnitCache::class)]
#[UsesClass(CacheKey::class)]
#[UsesClass(TranslationUnit::class)]
#[UsesClass(UnitId::class)]
#[UsesClass(Locale::class)]
#[UsesClass(LocalePair::class)]
final class UnitCacheTest extends TestCase
{
    private InMemoryCachePool $pool;

    private UnitCache $cache;

    private LocalePair $locales;

    protected function setUp(): void
    {
        $this->pool = new InMemoryCachePool();
        $this->cache = new UnitCache($this->pool);
        $this->locales = LocalePair::of(Locale::fromString('en'), Locale::fromString('pl'));
    }

    #[Test]
    public function it_returns_stored_translations_by_unit_id(): void
    {
        $this->cache->store([$this->unit('u1', 'Hello'), $this->unit('u2', 'Bye')], ['u1' => 'Cześć', 'u2' => 'Pa'], $this->locales, 'profile', 'provider');

        $found = $this->cache->fetch([$this->unit('u7', 'Bye'), $this->unit('u8', 'Hello'), $this->unit('u9', 'New')], $this->locales, 'profile', 'provider');

        self::assertSame(['u7' => 'Pa', 'u8' => 'Cześć'], $found);
    }

    #[Test]
    public function it_gives_every_unit_with_the_same_text_the_same_entry(): void
    {
        $this->cache->store([$this->unit('u1', 'Save')], ['u1' => 'Zapisz'], $this->locales, 'profile', 'provider');

        self::assertSame(['u4' => 'Zapisz', 'u5' => 'Zapisz'], $this->cache->fetch([$this->unit('u4', 'Save'), $this->unit('u5', 'Save')], $this->locales, 'profile', 'provider'));
    }

    #[Test]
    public function it_stores_only_units_with_a_translation_in_one_commit(): void
    {
        $this->cache->store([$this->unit('u1', 'Hello'), $this->unit('u2', 'Bye')], ['u2' => 'Pa', 'u9' => 'not in the batch'], $this->locales, 'profile', 'provider');

        self::assertSame(1, $this->pool->saves);
        self::assertSame(1, $this->pool->commits);
        self::assertSame(['u2' => 'Pa'], $this->cache->fetch([$this->unit('u1', 'Hello'), $this->unit('u2', 'Bye')], $this->locales, 'profile', 'provider'));
    }

    #[Test]
    public function it_does_not_commit_without_translations(): void
    {
        $this->cache->store([$this->unit('u1', 'Hello')], [], $this->locales, 'profile', 'provider');

        self::assertSame(0, $this->pool->commits);
    }

    #[Test]
    public function it_misses_for_another_provider_profile_or_locale_pair(): void
    {
        $this->cache->store([$this->unit('u1', 'Hello')], ['u1' => 'Cześć'], $this->locales, 'profile', 'provider');
        $units = [$this->unit('u1', 'Hello')];

        self::assertSame([], $this->cache->fetch($units, $this->locales, 'profile', 'other-provider'));
        self::assertSame([], $this->cache->fetch($units, $this->locales, 'other-profile', 'provider'));
        self::assertSame([], $this->cache->fetch($units, LocalePair::of(Locale::fromString('en'), Locale::fromString('de')), 'profile', 'provider'));
    }

    #[Test]
    public function it_ignores_entries_that_are_not_strings(): void
    {
        $unit = $this->unit('u1', 'Hello');
        $this->pool->values[CacheKey::of($unit, $this->locales, 'profile', 'provider')->toString()] = ['Cześć'];

        self::assertSame([], $this->cache->fetch([$unit], $this->locales, 'profile', 'provider'));
    }

    #[Test]
    public function it_treats_keys_the_pool_does_not_answer_as_misses(): void
    {
        // PSR-6 types getItems() as a bare iterable; a pool that leaves keys out or yields junk must not break lookups.
        $pool = self::createStub(CacheItemPoolInterface::class);
        $pool->method('getItems')->willReturn(['junk', 42]);

        self::assertSame([], new UnitCache($pool)->fetch([$this->unit('u1', 'Hello')], $this->locales, 'profile', 'provider'));
    }

    private function unit(string $id, string $text): TranslationUnit
    {
        return TranslationUnit::fromText(UnitId::fromString($id), UnitKind::Block, $text);
    }
}

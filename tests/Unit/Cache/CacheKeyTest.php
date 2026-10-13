<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Cache;

use HtmlTranslator\Cache\CacheKey;
use HtmlTranslator\Domain\InlineTag;
use HtmlTranslator\Domain\InlineTagKind;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Domain\LocalePair;
use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Domain\UnitId;
use HtmlTranslator\Domain\UnitKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheKey::class)]
#[UsesClass(TranslationUnit::class)]
#[UsesClass(UnitId::class)]
#[UsesClass(InlineTag::class)]
#[UsesClass(Locale::class)]
#[UsesClass(LocalePair::class)]
final class CacheKeyTest extends TestCase
{
    #[Test]
    public function it_is_a_valid_psr6_key_of_at_most_64_characters(): void
    {
        $key = $this->key()->toString();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_.]{1,64}$/', $key);
        self::assertSame(64, \strlen($key));
        self::assertStringStartsWith('html_translator.', $key);
    }

    #[Test]
    public function it_is_stable_across_processes(): void
    {
        // Pinned: a different key for the same inputs would turn every cached translation into a miss.
        self::assertSame('html_translator.bad3cecc2a5f66422c8253d1a32b5a160f41b7d30e78ab00', $this->key()->toString());
    }

    #[Test]
    public function it_ignores_the_unit_id_and_surrounding_whitespace(): void
    {
        $unit = TranslationUnit::fromText(UnitId::fromString('u42'), UnitKind::Block, "\n  Hello <g1>world</g1> ", [InlineTag::of(1, InlineTagKind::Paired)]);

        self::assertSame($this->key()->toString(), $this->key(unit: $unit)->toString());
    }

    /**
     * @return iterable<string, array{array{unit?: TranslationUnit, locales?: LocalePair, profile?: string, provider?: string}}>
     */
    public static function changes(): iterable
    {
        $paired = [InlineTag::of(1, InlineTagKind::Paired)];

        yield 'text' => [['unit' => TranslationUnit::fromText(UnitId::fromString('u1'), UnitKind::Block, 'Hello <g1>World</g1>', $paired)]];
        yield 'kind' => [['unit' => TranslationUnit::fromText(UnitId::fromString('u1'), UnitKind::Attribute, 'Hello world')]];
        yield 'tag kind' => [['unit' => TranslationUnit::fromText(UnitId::fromString('u1'), UnitKind::Block, 'Hello <g1>world</g1>', [InlineTag::of(1, InlineTagKind::Opaque)])]];
        yield 'target' => [['locales' => LocalePair::of(Locale::fromString('en'), Locale::fromString('de'))]];
        yield 'source' => [['locales' => LocalePair::of(Locale::fromString('en-GB'), Locale::fromString('pl'))]];
        yield 'auto-detected source' => [['locales' => LocalePair::of(null, Locale::fromString('pl'))]];
        yield 'profile' => [['profile' => 'other-profile']];
        yield 'provider' => [['provider' => 'llm:openai:gpt-4o:2026-10.5']];
    }

    /**
     * @param array{unit?: TranslationUnit, locales?: LocalePair, profile?: string, provider?: string} $change
     */
    #[Test]
    #[DataProvider('changes')]
    public function it_changes_with_every_part(array $change): void
    {
        self::assertNotSame($this->key()->toString(), $this->key(...$change)->toString());
    }

    #[Test]
    public function it_keeps_parts_apart(): void
    {
        // Parts are encoded, not concatenated: moving characters from one part to the next must change the key.
        self::assertNotSame($this->key(profile: 'ab', provider: 'c')->toString(), $this->key(profile: 'a', provider: 'bc')->toString());
    }

    private function key(?TranslationUnit $unit = null, ?LocalePair $locales = null, string $profile = 'profile', string $provider = 'llm:openai:gpt-4o:2026-10.4'): CacheKey
    {
        return CacheKey::of(
            $unit ?? TranslationUnit::fromText(UnitId::fromString('u1'), UnitKind::Block, 'Hello <g1>world</g1>', [InlineTag::of(1, InlineTagKind::Paired)]),
            $locales ?? LocalePair::of(Locale::fromString('en'), Locale::fromString('pl')),
            $profile,
            $provider,
        );
    }
}

<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Integration;

use HtmlTranslator\Issue;
use HtmlTranslator\Provider\Llm\LlmTranslationProvider;
use HtmlTranslator\Provider\Testing\PseudoLocalizationProvider;
use HtmlTranslator\Tests\Support\CacheableCallbackProvider;
use HtmlTranslator\Tests\Support\DomAssertions;
use HtmlTranslator\Tests\Support\GoldenFixtures;
use HtmlTranslator\Tests\Support\InMemoryCachePool;
use HtmlTranslator\Tests\Support\PseudoLocalizingChatModel;
use HtmlTranslator\TranslatedDocument;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression net over tests/Fixtures/<name>/ (ARCHITECTURE §8). Runs without network on every `composer test`.
 * Add a fixture with the add-html-fixture skill; no code change is needed.
 */
#[CoversNothing]
final class GoldenFixturesTest extends TestCase
{
    use DomAssertions;

    /**
     * @return iterable<string, array{string}>
     */
    public static function fixtures(): iterable
    {
        foreach (GoldenFixtures::all() as $name => $directory) {
            yield $name => [$directory];
        }
    }

    #[Test]
    #[DataProvider('fixtures')]
    public function it_matches_the_expected_pseudo_translation(string $directory): void
    {
        $this->assertMatchesTheFixture($directory, GoldenFixtures::translate($directory));
    }

    /**
     * Round trip through PromptBuilder, a chat model and ResponseParser: the prompt must carry every unit intact and
     * the parser must hand every answer back unchanged, so the result equals the pseudo-translation.
     */
    #[Test]
    #[DataProvider('fixtures')]
    public function it_matches_the_expected_pseudo_translation_through_the_llm_provider(string $directory): void
    {
        $this->assertMatchesTheFixture($directory, GoldenFixtures::translate($directory, new LlmTranslationProvider(PseudoLocalizingChatModel::create())));
    }

    /**
     * A second translation served from the unit cache must give the same document (ADR-0016).
     */
    #[Test]
    #[DataProvider('fixtures')]
    public function it_matches_the_expected_pseudo_translation_from_a_warm_cache(string $directory): void
    {
        $cache = new InMemoryCachePool();
        $first = GoldenFixtures::translate($directory, cache: $cache);
        $second = GoldenFixtures::translate($directory, new CacheableCallbackProvider(static fn(): never => self::fail('Every unit should come from the cache.'), new PseudoLocalizationProvider()->cacheId()), $cache);

        $this->assertMatchesTheFixture($directory, $second);
        self::assertSame($first->stats()->units, $second->stats()->cacheHits);
    }

    private function assertMatchesTheFixture(string $directory, TranslatedDocument $result): void
    {
        $name = basename($directory);
        $expected = GoldenFixtures::expected($directory) ?? self::fail(\sprintf(
            'Fixture "%s" has no %s. Generate it with `composer fixtures:update -- %1$s` and review it by hand.',
            $name,
            GoldenFixtures::EXPECTED,
        ));

        // The pseudo-localising providers never fail, so any issue is a bug (e.g. a literal "<g1>" in source text
        // turning into a pseudo-tag would show up here as an unknown or duplicate tag).
        self::assertSame([], array_map(
            static fn(Issue $issue): string => \sprintf('%s %s: %s', $issue->unitId, $issue->type->name, $issue->message),
            $result->issues(),
        ), \sprintf('Fixture "%s" produced issues.', $name));
        self::assertTrue($result->isComplete(), \sprintf('Fixture "%s" was not translated completely.', $name));

        // A failure shows a line-by-line diff of the normalized DOM. If the change is intended, regenerate with
        // `composer fixtures:update -- <name>` and review the new expected file by hand.
        if (GoldenFixtures::isFragment(GoldenFixtures::input($directory))) {
            self::assertSameFragmentStructure($expected, $result->html());
        } else {
            self::assertSameDocumentStructure($expected, $result->html());
        }
    }
}

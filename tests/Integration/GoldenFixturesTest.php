<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Integration;

use HtmlTranslator\Issue;
use HtmlTranslator\Tests\Support\DomAssertions;
use HtmlTranslator\Tests\Support\GoldenFixtures;
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
        $name = basename($directory);
        $expected = GoldenFixtures::expected($directory) ?? self::fail(\sprintf(
            'Fixture "%s" has no %s. Generate it with `composer fixtures:update -- %1$s` and review it by hand.',
            $name,
            GoldenFixtures::EXPECTED,
        ));

        $result = GoldenFixtures::translate($directory);

        // PseudoLocalizationProvider never fails, so any issue is a bug (e.g. a literal "<g1>" in source text
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

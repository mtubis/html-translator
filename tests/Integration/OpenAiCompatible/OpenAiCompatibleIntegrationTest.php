<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Integration\OpenAiCompatible;

use HtmlTranslator\Issue;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\OpenAiCompatibleConfig;
use HtmlTranslator\Provider\Llm\LlmTranslationProvider;
use HtmlTranslator\Tests\Support\DomAssertions;
use HtmlTranslator\Tests\Support\GoldenFixtures;
use HtmlTranslator\Tests\Support\IntegrationProviders;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Real models through OpenAiCompatibleChatModel: a few golden fixtures end to end, checking structure, not wording.
 * Runs only with `composer test:integration` and the provider's variables set (see .env.dist); calls cost money.
 */
#[Group('integration')]
#[CoversNothing]
final class OpenAiCompatibleIntegrationTest extends TestCase
{
    use DomAssertions;

    private const array FIXTURES = ['blog-article', 'table', 'docs-page-inline-code', 'rtl-target'];

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function providersAndFixtures(): iterable
    {
        // AnthropicIntegrationTest covers the provider with its own adapter.
        foreach (array_diff(IntegrationProviders::names(), ['anthropic']) as $provider) {
            foreach (self::FIXTURES as $fixture) {
                yield $provider . ' ' . $fixture => [$provider, $fixture];
            }
        }
    }

    #[Test]
    #[DataProvider('providersAndFixtures')]
    public function it_translates_the_fixture_keeping_its_structure(string $provider, string $fixture): void
    {
        $config = IntegrationProviders::config($provider);
        if (!$config instanceof OpenAiCompatibleConfig) {
            self::markTestSkipped(\sprintf('Set %s in .env (see .env.dist) to run %s.', IntegrationProviders::requiredVariable($provider), $provider));
        }

        $directory = GoldenFixtures::DIRECTORY . '/' . $fixture;
        $expected = GoldenFixtures::expected($directory) ?? self::fail(\sprintf('Fixture "%s" has no expected file.', $fixture));

        $result = GoldenFixtures::translate($directory, new LlmTranslationProvider(IntegrationProviders::chatModel($config)));

        self::assertSame([], array_map(
            static fn(Issue $issue): string => \sprintf('%s %s: %s', $issue->unitId, $issue->type->name, $issue->message),
            $result->issues(),
        ), \sprintf('%s produced issues for "%s".', $config->modelId(), $fixture));
        self::assertTrue($result->isComplete());
        self::assertSameSkeleton($expected, $result->html(), GoldenFixtures::isFragment(GoldenFixtures::input($directory)));
    }
}

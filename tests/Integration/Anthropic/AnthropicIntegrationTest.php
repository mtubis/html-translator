<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Integration\Anthropic;

use HtmlTranslator\Issue;
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicConfig;
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
 * The Claude Messages API through AnthropicChatModel: a few golden fixtures end to end, checking structure, not
 * wording. Runs only with `composer test:integration` and HTML_TRANSLATOR_ANTHROPIC_API_KEY set (see .env.dist);
 * calls cost money.
 */
#[Group('integration')]
#[CoversNothing]
final class AnthropicIntegrationTest extends TestCase
{
    use DomAssertions;

    private const array FIXTURES = ['blog-article', 'table', 'docs-page-inline-code', 'rtl-target'];

    /**
     * @return iterable<string, array{string}>
     */
    public static function fixtures(): iterable
    {
        foreach (self::FIXTURES as $fixture) {
            yield $fixture => [$fixture];
        }
    }

    #[Test]
    #[DataProvider('fixtures')]
    public function it_translates_the_fixture_keeping_its_structure(string $fixture): void
    {
        $config = IntegrationProviders::config('anthropic');
        if (!$config instanceof AnthropicConfig) {
            self::markTestSkipped(\sprintf('Set %s in .env (see .env.dist) to run the Anthropic integration test.', IntegrationProviders::requiredVariable('anthropic')));
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

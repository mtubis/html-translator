<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Tools\Eval;

use HtmlTranslator\Exception\LogicException;
use HtmlTranslator\Exception\ProviderException;
use HtmlTranslator\Provider\Llm\ChatResponse;
use HtmlTranslator\Provider\Llm\Testing\FakeChatModel;
use HtmlTranslator\Tests\Support\GoldenFixtures;
use HtmlTranslator\Tests\Support\PseudoLocalizingChatModel;
use HtmlTranslator\Tools\Eval\EvalRunner;
use HtmlTranslator\Tools\Eval\FixtureResult;
use HtmlTranslator\Tools\Eval\TokenCountingChatModel;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class EvalRunnerTest extends TestCase
{
    #[Test]
    public function it_records_each_fixture(): void
    {
        $fixtures = array_intersect_key(GoldenFixtures::all(), array_flip(['table', 'emoji']));
        $seen = [];

        $results = new EvalRunner(new TokenCountingChatModel(PseudoLocalizingChatModel::create()))
            ->run($fixtures, static function (FixtureResult $result) use (&$seen): void {
                $seen[] = $result->fixture;
            });

        self::assertSame(['emoji', 'table'], $seen);
        self::assertSame(['emoji', 'table'], array_map(static fn(FixtureResult $result): string => $result->fixture, $results));
        foreach ($results as $result) {
            self::assertTrue($result->complete);
            self::assertSame([], $result->issues);
            self::assertGreaterThan(0, $result->units);
            self::assertSame(1, $result->calls);
            self::assertNull($result->error);
        }
    }

    #[Test]
    public function it_records_whether_context_hints_were_sent(): void
    {
        $fixtures = array_intersect_key(GoldenFixtures::all(), array_flip(['blog-article', 'emoji']));

        $results = new EvalRunner(new TokenCountingChatModel(PseudoLocalizingChatModel::create()))->run($fixtures);

        $withContext = array_column(array_map(static fn(FixtureResult $result): array => [$result->fixture, $result->callsWithContext], $results), 1, 0);
        self::assertSame(['blog-article' => 1, 'emoji' => 0], $withContext);
    }

    #[Test]
    public function it_counts_issues_by_type(): void
    {
        $fixtures = array_intersect_key(GoldenFixtures::all(), array_flip(['emoji']));
        $model = FakeChatModel::answering(static fn(): ChatResponse => new ChatResponse('{"units": []}', 'm', 7, 1));

        $result = new EvalRunner(new TokenCountingChatModel($model))->run($fixtures)[0] ?? self::fail('No result.');

        self::assertFalse($result->complete);
        self::assertSame(['MissingTranslation' => $result->units], $result->issues);
        self::assertSame($result->units, $result->issueCount());
        self::assertSame(3, $result->calls, 'The first call and two corrective retries.');
        self::assertSame(21, $result->inputTokens);
    }

    #[Test]
    public function it_records_a_failed_translation_with_its_message(): void
    {
        $fixtures = array_intersect_key(GoldenFixtures::all(), array_flip(['emoji']));
        $model = FakeChatModel::withResponses(ProviderException::fromHttpResponse(401, 'openai returned HTTP 401: Incorrect API key provided.'));

        $result = new EvalRunner(new TokenCountingChatModel($model))->run($fixtures)[0] ?? self::fail('No result.');

        self::assertFalse($result->complete);
        self::assertSame(ProviderException::class . ': openai returned HTTP 401: Incorrect API key provided.', $result->error);
        self::assertSame(1, $result->calls);
    }

    #[Test]
    public function it_records_unexpected_errors_and_goes_on(): void
    {
        $fixtures = array_intersect_key(GoldenFixtures::all(), array_flip(['emoji', 'table']));
        $model = FakeChatModel::withResponses(new ProviderException('down'));

        $results = new EvalRunner(new TokenCountingChatModel($model))->run($fixtures);

        self::assertCount(2, $results);
        self::assertStringStartsWith(LogicException::class . ': FakeChatModel has no scripted response left', $results[1]->error ?? '');
    }
}

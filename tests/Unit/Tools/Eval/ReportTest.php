<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Tools\Eval;

use DateTimeImmutable;
use HtmlTranslator\Tools\Eval\FixtureResult;
use HtmlTranslator\Tools\Eval\Report;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ReportTest extends TestCase
{
    #[Test]
    public function it_names_the_file_after_time_and_model(): void
    {
        self::assertSame('20261011-140501-ollama-qwen2.5-7b-instruct-q4', $this->report()->fileName());
    }

    #[Test]
    public function it_tells_runs_of_one_model_in_two_json_modes_apart(): void
    {
        $report = new Report('ollama:llama3.1', '2026-10.1', new DateTimeImmutable('2026-11-01T18:00:00+00:00'), [], 'json_schema');
        $json = json_decode($report->toJson(), true, 8, \JSON_THROW_ON_ERROR);

        self::assertSame('20261101-180000-ollama-llama3.1-json-schema', $report->fileName());
        self::assertStringContainsString("- JSON mode: json_schema\n", $report->toMarkdown());
        self::assertIsArray($json);
        self::assertSame('json_schema', $json['jsonMode'] ?? null);
    }

    #[Test]
    public function it_summarises_the_run_in_markdown(): void
    {
        $markdown = $this->report()->toMarkdown();

        self::assertStringContainsString("# Eval: ollama:Qwen2.5-7B-Instruct:Q4\n", $markdown);
        self::assertStringContainsString('- Prompt version: 2026-10.1', $markdown);
        self::assertStringNotContainsString('JSON mode', $markdown, 'A model without JSON modes has none to report.');
        self::assertStringContainsString('- Complete: 1 of 2 fixtures', $markdown);
        self::assertStringContainsString('- Issues: 0, retried units: 2, model calls: 5 (3 with context hints)', $markdown);
        self::assertStringContainsString('- Tokens: 1200 in, 300 out (not counted: calls of 1 fixture that failed with an error)', $markdown);
        self::assertStringContainsString('- Cache hits: 4 of 10 units', $markdown);
        self::assertStringContainsString('| Fixture | Complete | Issues | Units | Cache hits | Batches | Retried | Calls | Context | Tokens in | Tokens out | Seconds |', $markdown);
        self::assertStringContainsString('| blog-article | yes | – | 10 | 4 | 1 | 2 | 3 | 3/3 | 1200 | 300 | 2.5 |', $markdown);
        self::assertStringContainsString('| table | no | error: ollama returned HTTP 500: a \| b c | 0 | 0 | 0 | 0 | 2 | 0/2 | – | – | 0.1 |', $markdown);
    }

    #[Test]
    public function it_lists_issue_types_in_markdown(): void
    {
        $report = new Report('m', '2026-10.1', new DateTimeImmutable(), [
            new FixtureResult('emoji', false, ['InvalidStructure' => 1, 'MissingTranslation' => 2], 3, 1, 4, 1.0, 5, null, null),
        ]);

        self::assertStringContainsString('| emoji | no | InvalidStructure 1, MissingTranslation 2 | 3 | 0 | 1 | 4 | 5 | 0/5 | – | – | 1.0 |', $report->toMarkdown());
    }

    #[Test]
    public function it_keeps_every_field_in_json(): void
    {
        $json = json_decode($this->report()->toJson(), true, 8, \JSON_THROW_ON_ERROR);

        self::assertIsArray($json);
        self::assertSame('ollama:Qwen2.5-7B-Instruct:Q4', $json['modelId'] ?? null);
        self::assertSame('2026-10.1', $json['promptVersion'] ?? null);
        self::assertSame('2026-10-11T14:05:01+00:00', $json['startedAt'] ?? null);
        self::assertSame(4, $json['cacheHits'] ?? null);
        self::assertSame([
            ['fixture' => 'blog-article', 'complete' => true, 'issues' => [], 'units' => 10, 'batches' => 1, 'retries' => 2, 'durationSeconds' => 2.5, 'calls' => 3, 'inputTokens' => 1200, 'outputTokens' => 300, 'error' => null, 'callsWithContext' => 3, 'cacheHits' => 4],
            ['fixture' => 'table', 'complete' => false, 'issues' => [], 'units' => 0, 'batches' => 0, 'retries' => 0, 'durationSeconds' => 0.1, 'calls' => 2, 'inputTokens' => null, 'outputTokens' => null, 'error' => "ollama returned HTTP 500: a | b\nc", 'callsWithContext' => 0, 'cacheHits' => 0],
        ], $json['results'] ?? null);
    }

    #[Test]
    public function it_fails_when_a_fixture_is_incomplete_or_ends_in_an_error(): void
    {
        $complete = new FixtureResult('emoji', true, [], 3, 1, 0, 1.0, 1, null, null);
        $incomplete = new FixtureResult('table', false, ['MissingTranslation' => 1], 3, 1, 2, 1.0, 3, null, null);
        $failed = new FixtureResult('blog-article', false, [], 0, 0, 0, 0.1, 1, null, null, 'ProviderException: down');

        self::assertSame(2, new Report('m', '2026-10.1', new DateTimeImmutable(), [$incomplete, $complete, $failed])->failureCount());
        self::assertFalse(new Report('m', '2026-10.1', new DateTimeImmutable(), [$complete])->hasFailures());
        self::assertFalse(new Report('m', '2026-10.1', new DateTimeImmutable(), [])->hasFailures(), 'An empty run has no failing fixture.');
        self::assertTrue(new Report('m', '2026-10.1', new DateTimeImmutable(), [$complete, $incomplete])->hasFailures());
        self::assertTrue(new Report('m', '2026-10.1', new DateTimeImmutable(), [$failed, $complete])->hasFailures());
    }

    private function report(): Report
    {
        return new Report('ollama:Qwen2.5-7B-Instruct:Q4', '2026-10.1', new DateTimeImmutable('2026-10-11T14:05:01+00:00'), [
            new FixtureResult('blog-article', true, [], 10, 1, 2, 2.5, 3, 1200, 300, callsWithContext: 3, cacheHits: 4),
            new FixtureResult('table', false, [], 0, 0, 0, 0.1, 2, null, null, "ollama returned HTTP 500: a | b\nc"),
        ]);
    }
}

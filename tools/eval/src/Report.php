<?php

declare(strict_types=1);

namespace HtmlTranslator\Tools\Eval;

use Closure;
use DateTimeImmutable;

/**
 * An eval run as Markdown (for people) and JSON (for comparing runs). Holds the model id and prompt version, never
 * the model's configuration, so no credential can end up in a report.
 */
final readonly class Report
{
    /**
     * @param list<FixtureResult> $results
     */
    public function __construct(
        public string $modelId,
        public string $promptVersion,
        public DateTimeImmutable $startedAt,
        public array $results,
    ) {}

    /**
     * e.g. "20261011-140501-openai-gpt-4o-mini", without the extension.
     */
    public function fileName(): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9.]+/', '-', strtolower($this->modelId)), '-');

        return $this->startedAt->format('Ymd-His') . '-' . $slug;
    }

    public function toMarkdown(): string
    {
        $complete = \count(array_filter($this->results, static fn(FixtureResult $result): bool => $result->complete));
        $lines = [
            \sprintf('# Eval: %s', $this->modelId),
            '',
            \sprintf('- Prompt version: %s', $this->promptVersion),
            \sprintf('- Started: %s', $this->startedAt->format(DATE_ATOM)),
            \sprintf('- PHP: %s', PHP_VERSION),
            \sprintf('- Complete: %d of %d fixtures', $complete, \count($this->results)),
            \sprintf('- Issues: %d, retried units: %d, model calls: %d', $this->sum(static fn(FixtureResult $result): int => $result->issueCount()), $this->sum(static fn(FixtureResult $result): int => $result->retries), $this->sum(static fn(FixtureResult $result): int => $result->calls)),
            \sprintf('- Tokens: %s in, %s out', $this->tokens(array_map(static fn(FixtureResult $result): ?int => $result->inputTokens, $this->results)), $this->tokens(array_map(static fn(FixtureResult $result): ?int => $result->outputTokens, $this->results))),
            '',
            '| Fixture | Complete | Issues | Units | Batches | Retried | Calls | Tokens in | Tokens out | Seconds |',
            '|---|---|---|---|---|---|---|---|---|---|',
        ];

        foreach ($this->results as $result) {
            $issues = null !== $result->error
                ? 'error: ' . str_replace(['|', "\n"], ['\|', ' '], $result->error)
                : ([] === $result->issues ? '–' : implode(', ', array_map(static fn(string $type, int $count): string => $type . ' ' . $count, array_keys($result->issues), $result->issues)));
            $lines[] = \sprintf(
                '| %s | %s | %s | %d | %d | %d | %d | %s | %s | %.1f |',
                $result->fixture,
                $result->complete ? 'yes' : 'no',
                $issues,
                $result->units,
                $result->batches,
                $result->retries,
                $result->calls,
                $result->inputTokens ?? '–',
                $result->outputTokens ?? '–',
                $result->durationSeconds,
            );
        }

        return implode("\n", $lines) . "\n";
    }

    public function toJson(): string
    {
        return json_encode([
            'modelId' => $this->modelId,
            'promptVersion' => $this->promptVersion,
            'startedAt' => $this->startedAt->format(DATE_ATOM),
            'php' => PHP_VERSION,
            'results' => array_map(get_object_vars(...), $this->results),
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * @param Closure(FixtureResult): int $value
     */
    private function sum(Closure $value): int
    {
        $sum = 0;
        foreach ($this->results as $result) {
            $sum += $value($result);
        }

        return $sum;
    }

    /**
     * @param list<?int> $counts
     */
    private function tokens(array $counts): string
    {
        $sum = null;
        foreach ($counts as $count) {
            if (null !== $count) {
                $sum = ($sum ?? 0) + $count;
            }
        }

        return null === $sum ? '–' : (string) $sum;
    }
}

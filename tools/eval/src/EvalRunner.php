<?php

declare(strict_types=1);

namespace HtmlTranslator\Tools\Eval;

use Closure;
use HtmlTranslator\Provider\Llm\LlmTranslationProvider;
use HtmlTranslator\Tests\Support\GoldenFixtures;
use Throwable;

/**
 * Translates golden fixtures with one chat model and records how each one went.
 */
final readonly class EvalRunner
{
    public function __construct(
        private TokenCountingChatModel $model,
    ) {}

    /**
     * @param array<string, string> $fixtures name → directory
     * @param (Closure(FixtureResult): void)|null $onResult called after each fixture
     *
     * @return list<FixtureResult>
     */
    public function run(array $fixtures, ?Closure $onResult = null): array
    {
        $results = [];
        foreach ($fixtures as $name => $directory) {
            $result = $this->runOne($name, $directory);
            if ($onResult instanceof Closure) {
                $onResult($result);
            }

            $results[] = $result;
        }

        return $results;
    }

    private function runOne(string $name, string $directory): FixtureResult
    {
        $this->model->reset();
        $started = hrtime(true);

        try {
            $document = GoldenFixtures::translate($directory, new LlmTranslationProvider($this->model));
        } catch (Throwable $exception) {
            // Anything stops only this fixture, so a paid run is not lost. Adapter messages carry no credentials.
            $usage = $this->model->usage();
            $error = $exception::class . ': ' . $exception->getMessage();

            return new FixtureResult($name, false, [], 0, 0, 0, (hrtime(true) - $started) / 1e9, $usage['calls'], $usage['inputTokens'], $usage['outputTokens'], $error, $usage['callsWithContext']);
        }

        $issues = [];
        foreach ($document->issues() as $issue) {
            $issues[$issue->type->name] = ($issues[$issue->type->name] ?? 0) + 1;
        }

        ksort($issues);
        $stats = $document->stats();
        $usage = $this->model->usage();

        return new FixtureResult($name, $document->isComplete(), $issues, $stats->units, $stats->batches, $stats->retries, $stats->durationSeconds, $usage['calls'], $usage['inputTokens'], $usage['outputTokens'], callsWithContext: $usage['callsWithContext']);
    }
}

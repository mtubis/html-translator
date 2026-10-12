<?php

declare(strict_types=1);

namespace HtmlTranslator\Tools\Eval;

/**
 * What one golden fixture came out like with one model.
 */
final readonly class FixtureResult
{
    /**
     * @param array<string, int> $issues issue type → count
     * @param ?string $error message of the exception that stopped the translation
     * @param int $callsWithContext model calls whose prompt carried context hints (page title, section headings)
     */
    public function __construct(
        public string $fixture,
        public bool $complete,
        public array $issues,
        public int $units,
        public int $batches,
        public int $retries,
        public float $durationSeconds,
        public int $calls,
        public ?int $inputTokens,
        public ?int $outputTokens,
        public ?string $error = null,
        public int $callsWithContext = 0,
    ) {}

    public function issueCount(): int
    {
        return array_sum($this->issues);
    }
}

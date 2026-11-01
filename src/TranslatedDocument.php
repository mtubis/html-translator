<?php

declare(strict_types=1);

namespace HtmlTranslator;

use HtmlTranslator\Exception\ArrayArgument;
use HtmlTranslator\Exception\InvalidArgumentException;

/**
 * Result of HtmlTranslator::translate() (ADR-0004). Partial failures do not throw: check isComplete() and issues().
 */
final readonly class TranslatedDocument
{
    /**
     * @param list<Issue> $issues
     *
     * @throws InvalidArgumentException when $issues is not a list of Issues
     */
    public function __construct(
        private string $html,
        private bool $complete,
        private array $issues,
        private TranslationStats $stats,
    ) {
        ArrayArgument::listOf('issues', $issues, Issue::class);
    }

    /**
     * Always valid HTML: a full document for document input, only the fragment for fragment input.
     */
    public function html(): string
    {
        return $this->html;
    }

    /**
     * True when every unit was translated. A unit saved by the TextNodesOnly fallback counts as translated,
     * although it is still reported in issues().
     */
    public function isComplete(): bool
    {
        return $this->complete;
    }

    /**
     * @return list<Issue>
     */
    public function issues(): array
    {
        return $this->issues;
    }

    public function stats(): TranslationStats
    {
        return $this->stats;
    }
}

<?php

declare(strict_types=1);

namespace HtmlTranslator;

/**
 * Result of HtmlTranslator::translate() (ADR-0004). Partial failures do not throw: check isComplete() and issues().
 */
final readonly class TranslatedDocument
{
    /**
     * @param list<Issue> $issues
     */
    public function __construct(
        private string $html,
        private bool $complete,
        private array $issues,
        private TranslationStats $stats,
    ) {}

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

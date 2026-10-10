<?php

declare(strict_types=1);

namespace HtmlTranslator;

use HtmlTranslator\Domain\UnitId;

/**
 * A non-fatal problem with one unit (ADR-0004); the configured FallbackStrategy has been applied.
 */
final readonly class Issue
{
    public function __construct(
        public UnitId $unitId,
        public IssueType $type,
        public string $message,
    ) {}
}

<?php

declare(strict_types=1);

namespace HtmlTranslator\Validation;

final readonly class ValidationResult
{
    /**
     * @param list<Violation> $violations
     */
    public function __construct(
        private array $violations,
    ) {}

    public function isValid(): bool
    {
        return [] === $this->violations;
    }

    /**
     * @return list<Violation>
     */
    public function violations(): array
    {
        return $this->violations;
    }

    /**
     * One "- message" line per violation, ready for a corrective-retry prompt; empty when valid.
     */
    public function describe(): string
    {
        return implode("\n", array_map(static fn(Violation $violation): string => '- ' . $violation->message, $this->violations));
    }
}

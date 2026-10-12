<?php

declare(strict_types=1);

namespace HtmlTranslator\Validation;

final readonly class ValidationResult
{
    /** Problems named in describe(); a hostile answer can have thousands, and the description goes back to the model. */
    private const int MAX_DESCRIBED = 10;

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
     * One "- message" line per violation, ready for a corrective-retry prompt; empty when valid. At most
     * MAX_DESCRIBED violations are named, then one line counts the rest; violations() keeps them all.
     */
    public function describe(): string
    {
        $lines = array_map(static fn(Violation $violation): string => '- ' . $violation->message, \array_slice($this->violations, 0, self::MAX_DESCRIBED));
        $more = \count($this->violations) - self::MAX_DESCRIBED;
        if ($more > 0) {
            $lines[] = \sprintf('- …and %d more %s.', $more, 1 === $more ? 'problem' : 'problems');
        }

        return implode("\n", $lines);
    }
}

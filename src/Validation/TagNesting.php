<?php

declare(strict_types=1);

namespace HtmlTranslator\Validation;

/**
 * Stack of open paired tags for one validation, with membership counts so lookups stay O(1).
 *
 * Closing a tag that has tags opened after it closes those too (as an HTML parser would) and remembers them,
 * so their own closing tags later are consumed silently instead of being reported as unmatched.
 *
 * @internal
 */
final class TagNesting
{
    /** @var list<int> */
    private array $stack = [];

    /** @var array<int, int> number → how many times it is on the stack */
    private array $onStack = [];

    /** @var array<int, int> number → closing tags still owed after an overlap */
    private array $implicitlyClosed = [];

    public function open(int $number): void
    {
        $this->stack[] = $number;
        $this->onStack[$number] = ($this->onStack[$number] ?? 0) + 1;
    }

    /**
     * @return list<int>|null null when nothing matches; otherwise the overlapping tags it closed, innermost first
     */
    public function close(int $number): ?array
    {
        if (0 === ($this->onStack[$number] ?? 0)) {
            if (0 < ($this->implicitlyClosed[$number] ?? 0)) {
                --$this->implicitlyClosed[$number];

                return [];
            }

            return null;
        }

        $overlapping = [];
        while (null !== ($top = array_pop($this->stack))) {
            $this->onStack[$top] = ($this->onStack[$top] ?? 1) - 1;

            if ($top === $number) {
                break;
            }

            $overlapping[] = $top;
            $this->implicitlyClosed[$top] = ($this->implicitlyClosed[$top] ?? 0) + 1;
        }

        return $overlapping;
    }

    /**
     * @return list<int> tags still open, in opening order
     */
    public function unclosed(): array
    {
        return $this->stack;
    }
}

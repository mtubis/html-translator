<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Documentation;

/**
 * A ```php block of a Markdown file and, when a ```text block follows it directly, the output it must print.
 */
final readonly class Snippet
{
    public function __construct(
        public string $code,
        public int $line,
        public ?string $expectedOutput = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace HtmlTranslator\Validation;

/**
 * One structural problem of a translation; the message is written for the model (sent back in corrective retries).
 */
final readonly class Violation
{
    public function __construct(
        public ViolationType $type,
        public string $message,
    ) {}
}

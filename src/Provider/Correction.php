<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider;

/**
 * Feedback for a corrective retry of a unit whose translation broke its structure: the rejected translation
 * and what is wrong with it. $problems is instruction text for a model (ValidationResult::describe(), one
 * "- …" line per problem), not meant to be parsed. Providers that cannot use feedback (deterministic engines)
 * may ignore it. Units that were refused or missing are re-sent without a correction.
 */
final readonly class Correction
{
    public function __construct(
        public string $previousTranslation,
        public string $problems,
    ) {}
}

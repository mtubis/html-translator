<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider;

/**
 * Feedback for a corrective retry of a unit.
 *
 * $previousTranslation is the rejected answer, or '' when there is nothing to show (the unit was missing from the
 * answer, or the answer was empty). Do not infer the reason from it: $problems says what to fix. Prompt builders
 * should leave out the "previous answer" part when it is empty.
 *
 * $problems is instruction text for a model (one "- …" line per problem, as ValidationResult::describe()), not meant
 * to be parsed. Providers that cannot use feedback (deterministic engines) may ignore corrections. Units the provider
 * refused (BatchResult::$failures) are re-sent without a correction.
 */
final readonly class Correction
{
    public function __construct(
        public string $previousTranslation,
        public string $problems,
    ) {}

    /**
     * For a unit the provider left out of its previous answer.
     */
    public static function forMissingUnit(): self
    {
        return new self('', '- Translate the text; it was missing from your previous answer.');
    }
}

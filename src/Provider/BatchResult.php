<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider;

use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Exception\UntrustedText;

/**
 * What a provider made of a batch, keyed by unit id ($unit->id->toString()):
 * - translations: unit text in the input format (see TranslationProviderInterface);
 * - failures: units the provider could not translate this time (timeouts, malformed parts of the answer); they are
 *   re-sent in corrective retries;
 * - permanentFailures: units the provider will not translate however often they are sent (content filter,
 *   unsupported language for that unit); they go straight to the fallback strategy.
 * Every reason ends up in an Issue message and in logs, so it must never contain API keys, tokens or other
 * credentials. Units in none of the maps are treated as missing; ids that are not in the batch are ignored.
 */
final readonly class BatchResult
{
    /**
     * @param array<string, string> $translations unit id → translated unit text (ADR-0008 format)
     * @param array<string, string> $failures unit id → why the provider could not translate it this time
     * @param array<string, string> $permanentFailures unit id → why the provider will never translate it
     *
     * @throws InvalidArgumentException when a unit appears in more than one map, or a failure has no reason
     */
    public function __construct(
        public array $translations,
        public array $failures = [],
        public array $permanentFailures = [],
    ) {
        $overlap = array_keys(array_intersect_key($translations, $failures) + array_intersect_key($translations, $permanentFailures) + array_intersect_key($failures, $permanentFailures));
        foreach ($overlap as $id) {
            throw new InvalidArgumentException(\sprintf('Unit "%s" is reported more than once (translated, failed or permanently failed).', UntrustedText::quote((string) $id)));
        }

        foreach ($failures + $permanentFailures as $id => $reason) {
            if ('' === trim($reason)) {
                throw new InvalidArgumentException(\sprintf('The failure of unit "%s" needs a reason.', UntrustedText::quote((string) $id)));
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider;

use HtmlTranslator\Exception\InvalidArgumentException;

/**
 * What a provider made of a batch, keyed by unit id ($unit->id->toString()):
 * - translations: unit text in the input format (see TranslationProviderInterface);
 * - failures: units the provider could not translate, with a reason that ends up in an Issue message.
 * Units in neither map are treated as missing; ids that are not in the batch are ignored.
 */
final readonly class BatchResult
{
    /**
     * @param array<string, string> $translations unit id → translated unit text (ADR-0008 format)
     * @param array<string, string> $failures unit id → why the provider could not translate it
     *
     * @throws InvalidArgumentException when a unit is both translated and failed, or a failure has no reason
     */
    public function __construct(
        public array $translations,
        public array $failures = [],
    ) {
        foreach (array_keys(array_intersect_key($translations, $failures)) as $id) {
            throw new InvalidArgumentException(\sprintf('Unit "%s" is both translated and failed.', $id));
        }

        foreach ($failures as $id => $reason) {
            if ('' === trim($reason)) {
                throw new InvalidArgumentException(\sprintf('The failure of unit "%s" needs a reason.', $id));
            }
        }
    }
}

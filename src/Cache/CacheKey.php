<?php

declare(strict_types=1);

namespace HtmlTranslator\Cache;

use HtmlTranslator\Domain\InlineTag;
use HtmlTranslator\Domain\LocalePair;
use HtmlTranslator\Domain\TranslationUnit;

/**
 * Cache key of one unit's translation (ARCHITECTURE §6.7, ADR-0016): a hash of everything that decides the answer —
 * the unit text without surrounding whitespace, its kind and inline tag shape, the locale pair, the profile
 * fingerprint and the provider id (which includes the model and prompt version). Not the unit id and not the context
 * hints (ADR-0013), so the same text is reused across documents and positions.
 *
 * The key is valid in every PSR-6 pool: [A-Za-z0-9_.], 64 characters.
 *
 * @internal used by UnitCache; the key format is not part of the public API
 */
final readonly class CacheKey
{
    /** Changes when the parts or the stored value change meaning; old entries are then never read again. */
    private const int FORMAT = 1;

    private const string PREFIX = 'html_translator.';

    private function __construct(
        private string $value,
    ) {}

    public static function of(TranslationUnit $unit, LocalePair $locales, string $profileFingerprint, string $providerId): self
    {
        // serialize() keeps the parts apart (length-prefixed), so no part can spill into the next one, and it takes
        // any bytes, unlike JSON.
        $parts = serialize([
            self::FORMAT,
            $unit->text,
            $unit->kind->name,
            array_map(static fn(InlineTag $tag): array => [$tag->number, $tag->kind->name], $unit->inlineTags),
            $locales->source?->toString(),
            $locales->target->toString(),
            $profileFingerprint,
            $providerId,
        ]);

        return new self(self::PREFIX . substr(hash('sha256', $parts), 0, 64 - \strlen(self::PREFIX)));
    }

    public function toString(): string
    {
        return $this->value;
    }
}

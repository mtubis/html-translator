<?php

declare(strict_types=1);

namespace HtmlTranslator\Validation;

use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Domain\UnitText;
use HtmlTranslator\Exception\UntrustedText;
use HtmlTranslator\Profile\GlossaryEntry;
use HtmlTranslator\Profile\TermMatcher;
use HtmlTranslator\Profile\TranslationProfile;

/**
 * Checks a structurally valid translation against the profile's terms (ADR-0017), on the plain text a reader sees and
 * with the matching rules of the prompt (TermMatcher, ADR-0012):
 * - a do-not-translate term in the source must be in the translation, with the profile's letter case when the source
 *   has it that way (a source in other case, such as a heading in capitals, only needs the term in any case);
 * - a glossary entry whose target equals its source (case-insensitively) is checked like that, in any case. Entries
 *   that translate their term are not checked: the model may inflect the target ("gałąź" → "gałęzi"). Equal means
 *   equal after mb_strtolower(), as in glossary deduplication.
 * Terms are quoted with UntrustedText (bounded, one line) since they end up in issue messages and logs.
 */
final readonly class TermChecker
{
    /**
     * @return list<string> what the translation breaks, in profile order (do-not-translate terms, then glossary
     *                      entries); empty when it keeps every term
     */
    public function check(TranslationUnit $unit, string $translation, TranslationProfile $profile): array
    {
        $source = [UnitText::plainText($unit->text)];
        $target = [UnitText::plainText($translation)];
        $inSource = TermMatcher::in($source);
        $exactInSource = TermMatcher::caseSensitiveIn($source);
        $inTarget = TermMatcher::in($target);
        $exactInTarget = TermMatcher::caseSensitiveIn($target);

        // Most terms are not in a given unit; the cheap source check comes first (a profile can have 500 of them).
        $problems = [];
        foreach ($profile->doNotTranslate as $term) {
            if (!$inSource->matches($term)) {
                continue;
            }

            $kept = $exactInSource->matches($term) ? $exactInTarget->matches($term) : $inTarget->matches($term);
            if (!$kept) {
                $problems[] = \sprintf('the do-not-translate term "%s" is missing or changed', UntrustedText::quote($term));
            }
        }

        foreach ($profile->glossary->matching($source)->entries() as $entry) {
            if ($this->keepsItsTerm($entry) && !$inTarget->matches($entry->target)) {
                $problems[] = \sprintf('the glossary term "%s" should stay "%s"', UntrustedText::quote($entry->source), UntrustedText::quote($entry->target));
            }
        }

        return $problems;
    }

    private function keepsItsTerm(GlossaryEntry $entry): bool
    {
        return mb_strtolower($entry->source) === mb_strtolower($entry->target);
    }
}

<?php

declare(strict_types=1);

namespace HtmlTranslator\Profile;

/**
 * What happens to technical terms and names (loan words). Explicit terms belong in the glossary or the
 * do-not-translate list of a TranslationProfile; this policy covers the categories the model has to recognise.
 * Backed by strings so configuration can use TermPolicy::from().
 */
enum TermPolicy: string
{
    /**
     * Translate technical terms, using the established target-language equivalent where one exists. The default:
     * it is what models do unasked, so no prompt instruction is rendered for it.
     */
    case TranslateAll = 'translate_all';

    /**
     * Keep the source-language technical terms that professionals in the target language use untranslated
     * (e.g. "pull request", "framework"); translate the rest.
     */
    case PreferOriginalTechnicalTerms = 'prefer_original_technical_terms';

    /** Keep every technical term, product name and brand name in the source language. */
    case KeepOriginal = 'keep_original';
}

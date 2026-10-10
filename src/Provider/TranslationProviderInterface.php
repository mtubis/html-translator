<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider;

use HtmlTranslator\Exception\ProviderException;

/**
 * Port the core depends on (ADR-0005): turns a batch of units into translated unit text.
 *
 * Input: each unit's text is escaped text with numbered pseudo-tags (ADR-0003, ADR-0008): <gN>…</gN> for inline
 * elements with content, <xN/> for void and opaque ones. Surrounding whitespace has been removed; the core restores it.
 *
 * Output, per unit, in BatchResult::$translations keyed by the unit id:
 * - every pseudo-tag of the input exactly once and in its form; tags may move to fit word order but stay nested;
 * - no other markup; attribute and title units (UnitKind::Attribute, UnitKind::Title) get no tags at all;
 * - not empty, no added surrounding whitespace;
 * - &, < and > escaped as &amp;, &lt;, &gt; (decoding is lenient, ADR-0009, but must not be relied on).
 * The core validates every translation and re-sends broken units with a Correction, up to the configured number of
 * corrective retries.
 *
 * Failures: a unit the provider cannot translate goes into BatchResult::$failures with a reason; it is re-sent like
 * a broken unit, so a refusal that will not change on retry still costs those calls. When the whole batch cannot be
 * handled (service down after transport retries, unsupported locale pair, authentication), throw ProviderException,
 * and wrap transport and decoding errors in it: callers rely on every exception implementing TranslatorException.
 */
interface TranslationProviderInterface
{
    /**
     * @throws ProviderException when the batch cannot be translated at all
     */
    public function translate(TranslationBatch $batch): BatchResult;
}

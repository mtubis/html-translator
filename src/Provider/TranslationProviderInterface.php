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
 * The core validates every translation and re-sends broken units, and units missing from the answer, with a
 * Correction, up to the configured number of corrective retries.
 *
 * Failures: a unit the provider cannot translate goes into BatchResult::$failures with a reason and is re-sent without
 * a Correction. If retrying cannot help (content filter, unsupported language for that unit), put it into
 * BatchResult::$permanentFailures instead: it gets no corrective retries and the fallback strategy applies right away
 * (with TextNodesOnly its text nodes are sent once more). Reasons end up in issues and logs, so they must never contain
 * API keys, tokens or other credentials.
 *
 * When the whole batch cannot be handled (service down after transport retries, unsupported locale pair,
 * authentication), throw ProviderException, and wrap transport and decoding errors in it: callers rely on every
 * exception implementing TranslatorException. The core does not retry a batch that threw: transport retries
 * (429/5xx, Retry-After, backoff) belong in the provider. A ProviderException on the first call of a batch reaches the
 * caller as it is; on a corrective retry it becomes issues, and with FallbackStrategy::Throw it is the previous
 * exception of the one thrown.
 *
 * Usage: report the tokens of every call made for the batch (own retries included) in BatchResult::$usage; leave it
 * null when the vendor reports none.
 */
interface TranslationProviderInterface
{
    /**
     * @throws ProviderException when the batch cannot be translated at all
     */
    public function translate(TranslationBatch $batch): BatchResult;
}

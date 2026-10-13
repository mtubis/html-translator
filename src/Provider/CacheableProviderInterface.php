<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider;

/**
 * Optional companion of TranslationProviderInterface (ADR-0016): a provider whose translations may be reused from the
 * unit cache. Providers that do not implement it work as before and are never cached.
 */
interface CacheableProviderInterface extends TranslationProviderInterface
{
    /**
     * Identifies everything of the provider that changes its translations: engine, model, prompt version, settings
     * that change the output (e.g. "llm:openai:gpt-4o-mini:2026-10.4"). Translations cached under another id are not
     * reused, so change it whenever the output would change. Not per-batch data: locales, profile and unit text are
     * part of the cache key already. Stable across calls and processes, not empty, a single line without control
     * characters, and free of credentials (it is hashed into cache keys, but may end up in logs).
     *
     * A decorator that changes translations (post-processing, its own glossary) composes its settings with the
     * wrapped provider's id, e.g. "my-decorator:v1:" . $inner->cacheId(); one that wraps a provider that is not
     * cacheable must not implement this interface.
     */
    public function cacheId(): string;
}

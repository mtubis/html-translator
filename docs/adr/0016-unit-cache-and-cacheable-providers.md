# 0016. Unit cache and cacheable providers

- Status: proposed
- Date: 2026-10-13

## Context
Re-translating a slightly edited page should not pay for unchanged units (ARCHITECTURE §6.7, #59). The cache key must
change whenever the answer could change: unit text and shape, locale pair, profile, model and prompt version. The core
only sees `TranslationProviderInterface`, which says nothing about models or prompts, and third-party providers already
implement it.

Alternatives considered:
- *`cacheId(): ?string` on `TranslationProviderInterface`*: every existing implementation breaks. Rejected.
- *Key built from `ChatModelInterface::modelId()` and `PromptBuilder::VERSION` in the core*: ties the cache to the LLM
  provider and leaves other engines uncacheable. Rejected.
- *Context hints in the key*: makes almost every unit a miss; ADR-0013 already accepted reuse across contexts. Rejected.

## Decision
- A provider opts in by implementing `Provider\CacheableProviderInterface` (extends `TranslationProviderInterface`) with
  `cacheId(): string`: non-empty, single-line, credential-free, stable across calls and processes, without per-batch
  data, and different whenever the output would differ. `LlmTranslationProvider` returns
  `llm:<modelId>:<PromptBuilder::VERSION>`; `ChatModelInterface::modelId()` therefore names every adapter setting that
  changes answers (`AnthropicConfig`: `+effort=<level>`, `+fallbacks`), and a change to the text `ResponseParser`
  returns needs a new `PromptBuilder::VERSION`. `PseudoLocalizationProvider` returns `pseudo-localization`. A
  decorator that changes translations prefixes the wrapped provider's id; one around a non-cacheable provider does
  not implement the interface.
- `HtmlTranslator` takes `cache: ?CacheItemPoolInterface` (PSR-6). With a provider that is not cacheable, nothing is
  cached and a notice is logged per `translate()` call; no exception, so decorators keep working.
- `Cache\CacheKey` is `html_translator.` plus 48 hex characters of SHA-256 over the serialized parts: a format version,
  the unit text without surrounding whitespace, the unit kind, the inline tag numbers and kinds, source (null for auto)
  and target locale, `TranslationProfile::fingerprint()` and the provider's `cacheId()`. Not the unit id, not the
  context hints.
- `TranslationProfile::fingerprint()` hashes every setting in a fixed, process-independent form; glossary and
  do-not-translate order count.
- All units are looked up before batching. A hit is untrusted: it must be a string and pass `StructureValidator` for
  its unit, otherwise the unit is translated again and its entry overwritten.
- Only translations that passed validation in the first call or a corrective retry are stored, per batch (one
  `commit()`), so earlier batches survive a later `ProviderException`. Failed, refused, missing units and the output
  of the `TextNodesOnly` fallback are never stored.
- No expiry is set; TTL is configured on the pool. `TranslationStats::$cacheHits` counts units served from the cache;
  they are part of `$units` and count as done in `Progress`; when every unit is a hit, `onProgress` is called once
  with no batches.

## Consequences
- Third-party providers keep working and simply are not cached until they implement the interface.
- A prompt or model change invalidates the cache through `cacheId()`; a provider that changes its output without
  changing `cacheId()` serves stale translations. `OpenAiCompatibleConfig` ids name the preset and model, not the
  host: two servers with the same model name share entries; use separate pools if they must not.
- Identical units within one document are still translated once each in the first run (no in-run deduplication).
- Anyone who can write to the pool can inject translation text, but never markup: hits are validated and inserted as
  text nodes (ADR-0003).

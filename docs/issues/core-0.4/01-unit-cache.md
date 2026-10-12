# PSR-6 unit cache

## Context
ARCHITECTURE §4 (`cache:` collaborator) and §6.7. Re-translating a slightly edited page should not pay for unchanged units. The cache is optional and works per unit, on any PSR-6 pool (`psr/cache` is already a runtime dependency).

## Scope
- `HtmlTranslator` gets the optional named parameter `cache: ?CacheItemPoolInterface` (ADR-0010).
- `Cache\CacheKey`: a hash of
  - the normalized unit text;
  - the unit kind and inline tag shape;
  - the locale pair;
  - a profile fingerprint (`TranslationProfile::fingerprint()`, stable across processes);
  - the provider/model id;
  - the prompt version.

  Valid as a PSR-6 key (characters, length).
- How models are identified: providers expose an id for the cache key. Add `TranslationProviderInterface::cacheId(): ?string` (null = do not cache) or an optional interface. This needs an ADR, because it changes a public port.
- `Cache\UnitCache` wraps the pool:
  - looks up all units of a document before batching;
  - stores only validated translations;
  - never stores fallback output or failures.
- `TranslationStats` gains `cacheHits`.
- Context hints are not part of the key (ADR-0013 accepted this).

## Acceptance criteria
- [ ] Translating the same document twice calls the provider once; the second result is identical and `cacheHits` equals the unit count.
- [ ] A change in profile, model, prompt version or locale pair misses the cache.
- [ ] Failed, refused and fallback units are not cached; a corrupt cache entry (not a string, or invalid structure) is ignored and re-translated.
- [ ] Without a cache nothing changes (golden fixtures).
- [ ] `composer check` green.

## Open questions (decide before starting)
- **Port change.** `cacheId()` on the provider port or a separate `CacheableProviderInterface`? Recommendation: a separate optional interface. That way third-party providers keep working and are simply not cached.
- **TTL.** Recommendation: none by default, configurable through the pool.

## Out of scope
Translation memory across documents with fuzzy matching (post-1.0), cache warm-up tools.

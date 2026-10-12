# 0010. Public API shape of Core 0.1

- Status: accepted; `batchSize` clause amended by [0015](0015-token-budget-batching.md)
- Date: 2026-10-11

## Context
ARCHITECTURE §4 sketches the stable surface including `cache`, `clock` and `TranslationProfile`, which arrive in 0.3/0.4. Implementing the orchestration (#8) raised questions §4 does not answer: how to add parameters later without breaking callers, what to do with options that are known to be temporary, which collaborators are injectable, and what the fallback strategy covers besides structural failures.

Alternatives considered:
- *Reserve every future parameter now (e.g. a `cache` that is ignored)*: dead options in a public API mislead users. Rejected.
- *Builder objects instead of constructors*: more API surface for no gain in PHP 8 with named arguments. Rejected.

## Decision
- `HtmlTranslator` and `TranslationOptions` are configured with **named arguments** beyond the first parameter (`provider`). Positional order is not part of the public API; new parameters (`cache`, `clock`, `profile`) are inserted where they read best and always have defaults.
- `HtmlTranslator` collaborators are injectable as optional named parameters with defaults: `logger`, `loader`, `segmenter`, `validator`, `reassembler`, `skipRules`, `translatableAttributes`.
- `TranslationOptions::$batchSize` is **provisional**: it exists until the token-based `Batcher` (0.4), which will take a token budget; then `batchSize` becomes an upper bound and is deprecated if it serves no purpose.
- `FallbackStrategy` applies to every unit that has no valid translation after corrective retries: invalid structure, refused by the provider, or missing from its answer. With `Throw`, invalid structure raises `StructureValidationException`; a refused or missing unit raises `ProviderException`.
- A `ProviderException` on a corrective retry or in the `TextNodesOnly` fallback call does not abort the document: the affected units are reported as issues (ADR-0004). Only a failure of a batch's first call propagates.

## Consequences
- Callers must use named arguments for everything but the provider; README and examples do.
- Adding cache, clock and profile in later versions is not a breaking change.
- `batchSize` users get a deprecation notice in 0.4 at the earliest. (Amended by ADR-0015: `batchSize` stays as the unit cap and is not deprecated.)

# Provider port, PseudoLocalizationProvider and HtmlTranslator orchestration

## Context
ADR-0004, ADR-0005, ARCHITECTURE §4–§6. Batching is naive here (one batch per N units, N configurable); the token-based `Batcher` comes in 0.4.

## Scope
- `Provider\TranslationProviderInterface`, `Provider\TranslationBatch`, `Provider\BatchResult` (translations per unit id + per-unit failures).
- `Provider\Testing\PseudoLocalizationProvider` — deterministic accent + bracket transformation that preserves pseudo-tags; option to deliberately corrupt every k-th unit (drop a tag) to exercise retries/fallback.
- `FallbackStrategy` enum, `Issue`, `IssueType`, `TranslationOptions`, `TranslatedDocument`, `TranslationStats` (units, batches, retries, duration for now), `Progress`.
- `HtmlTranslator::translate()` wiring loader → segmenter → batches → provider → validator (+ corrective retries) → fallback → reassembler.
- Exception hierarchy under `Exception\` with marker interface `TranslatorException`.

## Acceptance criteria
- [ ] End-to-end test: fixture in → pseudo-localized HTML out, `isComplete() === true`.
- [ ] With the corrupting provider: issues recorded, each `FallbackStrategy` case tested, `Throw` throws `StructureValidationException`.
- [ ] `onProgress` called once per batch.
- [ ] Public classes documented with PHPDoc where types alone don't say enough; README "Quick start" section updated.
- [ ] `composer check` green.

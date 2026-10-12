# Batcher with a token budget and a TokenEstimator

## Context
ARCHITECTURE §6.3 and ADR-0010 (`TranslationOptions::$batchSize` is provisional). `HtmlTranslator` splits the units with `array_chunk($units, $batchSize)`, so a batch of long paragraphs can exceed a model's context or output limit, while a batch of short labels wastes calls. Batches should be sized by an approximate token budget. Order is preserved and units are never split.

## Scope
- `Batching\TokenEstimatorInterface::estimate(string $text): int` (a port: vendors offer exact counting endpoints), with a default heuristic implementation `Batching\CharacterTokenEstimator`. Proposed heuristic: about 4 characters per token for Latin and Cyrillic scripts, 1 per character for CJK, rounded up, plus a fixed overhead per unit for the JSON envelope.
- `Batching\Batcher::batches(list<TranslationUnit>, int $tokenBudget, int $maxUnits): list<list<TranslationUnit>>`:
  - fills a batch until the next unit would exceed the budget or `$maxUnits`;
  - a single unit over the budget gets a batch of its own;
  - order is preserved.
- `TranslationOptions::$tokenBudget` (named, default e.g. 2,000 input tokens per batch). `batchSize` stays as the unit cap. Its deprecation is decided in the ADR.
- `HtmlTranslator` takes `?Batcher` / `?TokenEstimatorInterface` as optional named collaborators (ADR-0010) and uses the Batcher instead of `array_chunk`.
- New ADR: budget semantics (input tokens of unit texts, not the whole prompt), defaults, and what happens to `batchSize`.

## Acceptance criteria
- [ ] Units are never split or reordered; a unit over the budget is a batch of its own.
- [ ] A document of many short units needs fewer calls than with `batchSize: 20`; a document of long units stays within the budget (table tests on the Batcher).
- [ ] The estimator is within a documented error bound on the golden fixtures (test against recorded token counts from `tools/eval` or a fixed table).
- [ ] Existing golden fixtures and orchestration tests pass unchanged where the budget is not reached.
- [ ] `composer check` green.

## Open questions (decide before starting)
- **Budget scope.** Should the budget also cover the system prompt (profile, glossary) and the expected output? Recommendation: input unit texts only, with documented headroom. The prompt is roughly constant per batch.
- **`batchSize`.** Deprecate it or keep it as `maxUnitsPerBatch`? Recommendation: keep it as an upper bound under its current name. ADR-0010 promised a deprecation only "if it serves no purpose".

## Out of scope
Exact vendor token counting endpoints (later implementations of the port), output-token budgeting via `maxOutputTokens` (follow-up).

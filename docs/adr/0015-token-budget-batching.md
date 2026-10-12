# 0015. Token budget batching

- Status: proposed; amends [0010](0010-public-api-of-core-0-1.md) (what happens to `batchSize`)
- Date: 2026-10-13

## Context
`HtmlTranslator` cut the units into fixed chunks of `batchSize` (20). A chunk of long paragraphs can exceed a model's
context or output limit, and a chunk of short labels wastes calls. ADR-0010 made `batchSize` provisional until a
token-based batcher arrived, and said it would be deprecated if it served no purpose. Exact token counts depend on the
vendor's tokenizer and need a network call.

Alternatives considered:
- *Budget the whole prompt (instructions, profile, glossary, context hints)*: the Batcher would have to know
  `PromptBuilder`, which breaks the rule that `Batching` depends only on `Domain` and `Profile`. The instructions are
  roughly constant per batch anyway. Rejected.
- *Deprecate `batchSize` in favour of the budget alone*: a single cap on units still serves a purpose. It bounds how
  many units one failed call affects, and how many ids a small model has to map back. Rejected.
- *A real tokenizer (tiktoken port or vendor endpoint) by default*: this needs a new dependency or I/O. It stays
  possible behind the port. Rejected as the default.

## Decision
- `Batching\Batcher` groups units in document order. A unit costs `TokenEstimatorInterface::estimate($unit->text)`
  plus `Batcher::UNIT_OVERHEAD` (20 tokens for its JSON envelope in the prompt). A batch is closed when the next unit
  would exceed `TranslationOptions::$tokenBudget` or the batch already holds `TranslationOptions::$batchSize` units.
  Units are never split. A unit over the budget gets a batch of its own.
- The budget covers **input unit texts only**: no instructions, profile, context hints or answer. Its default,
  2,000 tokens, leaves ample headroom for those within any current model's context. Output length is budgeted
  separately (follow-up: `maxOutputTokens`).
- `batchSize` keeps its name and default (20) as the upper bound of units per batch, and is **not deprecated**.
  Callers who translate many short units raise it to benefit from the budget.
- `TokenEstimatorInterface` is a port, so vendors' exact counters can plug in later. Its default is
  `CharacterTokenEstimator`, a heuristic:
  - a pseudo-tag or a character reference costs 3 tokens;
  - a CJK character costs 1;
  - a character outside the BMP or in U+2600–U+27BF (emoji, dingbats) costs 2;
  - every other character costs ⅓, rounded up.

  It was tuned against tiktoken `o200k_base` counts of the golden fixtures and of sentences in nine scripts. It must
  stay within 0.8–2.5× of them (`tests/Integration/TokenEstimatorAccuracyTest`). Overestimating is preferred, since it
  only costs calls.
- `HtmlTranslator` takes the Batcher as the optional named collaborator `batcher`. A custom estimator is passed as
  `new Batcher($estimator)`, not as a separate parameter.

## Consequences
- With the defaults, documents whose units are short behave as before. Long units are now split across more calls,
  and every batch that holds several units stays within the budget.
- The recorded counts in `TokenEstimatorAccuracyTest` must be regenerated when a fixture's units change, or when a
  fixture is added.
- Text dense with diacritics is underestimated by up to 20 %. A budget close to a model's real limit is unsafe.
- The budget is not a hard bound on the input: corrective retries add feedback (previous translation, problems) and
  the instructions grow with the profile and glossary, none of which is budgeted.

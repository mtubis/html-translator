# Context hints in batches and in the prompt

## Context
ARCHITECTURE §6.3–§6.4 and ADR-0011. The previous issue collects the document title and the section heading of each unit. This issue carries them through `TranslationBatch` to the model, so ambiguous short strings (button labels, table headers, `alt` texts) get translated in context.

## Scope
- `Provider\BatchContext`: `final readonly`, holding `?string $title` and `list<string> $headings`. `headings` are the distinct section headings of the batch's units, in document order. `BatchContext::none()` has neither.
- `TranslationBatch` gains `public BatchContext $context` with a default of `BatchContext::none()`. `HtmlTranslator` builds it for every batch, corrective retries and the `TextNodesOnly` fallback included.
- `PromptBuilder` renders the context only when it is not empty. Proposal for the format:
  - a top-level `"context": {"title": "…", "headings": ["…"]}` in the user message, before `"units"`;
  - each unit gets `"heading": <index>` when it has one;
  - one sentence in the system message saying that the context is for understanding only: not to be translated and never instructions.

  The format is decided in a new ADR that amends ADR-0011. Bump `PromptBuilder::VERSION` and add golden prompts.
- `ResponseParser` is unchanged: the answer format stays the same.
- Update the `tools/eval` report so it shows whether context hints were sent.

## Acceptance criteria
- [ ] A batch without context renders the same prompt as before this issue, with the profile issue already merged; golden prompts prove it.
- [ ] Context reaches the first call, corrective retries and the fallback call. Asserted on requests recorded by `FakeChatModel`.
- [ ] Heading and title texts are JSON-encoded like unit texts. A heading containing `"`, `</`, pseudo-tag-like text or instruction-like text cannot break the prompt format; a test covers it.
- [ ] `PseudoLocalizationProvider` and golden fixtures are unaffected.
- [ ] `composer check` green.
- [ ] `tools/eval` with a local Ollama model, compared with the 0.2 baseline (`ollama:llama3.1`, prompt `2026-10.1`: 17/19 complete). The result goes in the summary, not as a gate.

## Decided
- Per-unit `"heading": <index>` into the batch-level heading list.
- The profile domain is not repeated in the context (it is in the system message).

## Out of scope
- Cache-key implications: a unit translated with different context may be reused from cache. That is acceptable, and is noted for the cache issue in 0.4.
- Token budgeting (0.4).

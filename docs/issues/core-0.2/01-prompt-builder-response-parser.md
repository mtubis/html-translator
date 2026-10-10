# PromptBuilder and ResponseParser (versioned prompt, JSON unit format)

## Context
ARCHITECTURE §6.4, ADR-0003 (pseudo-tags), ADR-0008/0009 (unit text format), `Provider\Correction` contract (#21). Prompt construction and parsing are written once and shared by every vendor (ADR-0005). The prompt version is part of the future cache key (§6.7). Steering by `TranslationProfile` arrives in 0.3; this issue leaves a clear extension point.

## Scope
- `Provider\Llm\PromptBuilder::build(TranslationBatch): ChatRequest`
  - System message: task, source/target locale (or "detect the source language"), the unit text format (pseudo-tags `<gN>…</gN>`/`<xN/>` exactly once each, may move but stay nested, no other markup, `&amp;`/`&lt;`/`&gt;` escaping, plain-text units get no tags), and the answer format.
  - User message: units as JSON `[{"id": "u12", "kind": "block", "text": "…"}]`; for units with a `Correction`, the previous answer (omitted when empty) and the problems.
  - `PromptBuilder::VERSION` constant (e.g. `"2026-10.1"`), documented as part of the cache key; `jsonResponse: true`.
- `Provider\Llm\ResponseParser::parse(string $content, TranslationBatch): BatchResult`
  - Accepts the JSON object/array of `{"id", "text"}`; tolerates a Markdown code fence, leading/trailing prose and a single trailing comma; rejects anything else as a `ProviderException` for the batch.
  - Ids not in the batch are ignored, duplicate ids → the first wins and the rest are reported as failures, missing ids are left out (the core treats them as missing), non-string text → failure for that unit.
- ADR-0011 "Prompt and response format of the LLM provider" (proposed): the JSON shape, the versioning rule (any change to wording or format bumps `VERSION`), how corrections are rendered.

## Acceptance criteria
- [ ] Golden tests for the rendered prompt (system + user message) for: plain batch, auto-detected source, plain-text units, units with corrections (non-empty and empty previous answer) — changes to the prompt show up as reviewable diffs.
- [ ] Parser table tests: clean JSON, code fence, prose around JSON, trailing comma, unknown/duplicate/missing ids, wrong types, invalid JSON (→ `ProviderException`).
- [ ] No vendor-specific code; works purely on `ChatRequest`/`ChatResponse` strings.
- [ ] `composer check` green; Infection MSI for `src/Provider/Llm` ≥ 90 %.

## Out of scope
`TranslationProfile`, glossary and term policy (0.3), context hints (0.3), token budgeting (0.4).

# Find the refused unit instead of failing the whole batch

## Context
When a model refuses a batch (`FinishReason::Refusal` or `ContentFilter`), `LlmTranslationProvider` marks **every** unit of the batch as a permanent failure. One problematic sentence therefore leaves up to 20 harmless units untranslated, and token-budget batching (#58) made batches larger.

## Scope
- On a refused batch with more than one unit, split it in halves and send each half again, recursively, until the refused units are isolated. Only those units fail permanently. The others are translated.
- Bound the extra calls: at most `2 × log2(n)` additional calls per refused batch, and stop when a half is refused again at the single-unit level.
- The extra calls count in `stats()`. Each refused unit gets its own issue naming the refusal.
- New ADR (or an amendment of ADR-0011): when to split, the cost bound, and how this interacts with corrective retries and the `TextNodesOnly` fallback.

## Acceptance criteria
- [ ] A batch of 8 units where the model refuses only when unit 5 is present ends with 7 translated units and 1 `ProviderFailure` issue (`FakeChatModel` scenario).
- [ ] The number of calls stays within the documented bound.
- [ ] A batch refused for every unit still fails as a whole within the bound.
- [ ] `composer check` green.

## Open questions (decide before starting)
- **Opt-out.** Should splitting be configurable (`LlmTranslationProvider` option), since it costs calls? Recommendation: on by default, with a constructor flag to turn it off.

## Out of scope
Rewriting refused text or retrying with a different model; refusal fallbacks are the vendor's feature (`AnthropicConfig::$refusalFallbacks`).

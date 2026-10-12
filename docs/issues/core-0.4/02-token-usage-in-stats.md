# Token usage in TranslationStats

## Context
ARCHITECTURE §4 lists tokens in and out in `TranslationStats`. Today only `tools/eval` counts them, by wrapping the chat model. Callers need them for cost tracking.

## Scope
- `BatchResult` gains optional `?TokenUsage $usage` (input and output tokens, null when unknown). `LlmTranslationProvider` fills it from `ChatResponse`, summing parse retries.
- `HtmlTranslator` sums usage over all provider calls of a document: first calls, corrective retries, the text-node fallback. `TranslationStats` gains `?int $inputTokens` and `?int $outputTokens`, which stay null while no call reported any.
- `tools/eval` reads the stats instead of its own counting wrapper. Keep the wrapper only if something else needs it.

## Acceptance criteria
- [ ] Usage is summed over every call, retries and fallback included (tests with `FakeChatModel` token counts).
- [ ] Providers that report nothing (`PseudoLocalizationProvider`) leave the stats null, not 0.
- [ ] `composer check` green.

## Out of scope
Cost in money (vendor price lists change), per-unit attribution.

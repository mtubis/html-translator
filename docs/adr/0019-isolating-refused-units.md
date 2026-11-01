# 0019. Isolating refused units

- Status: proposed
- Date: 2026-11-01

## Context
A model that refuses a batch (`FinishReason::Refusal` or `ContentFilter`) refuses the whole answer, so
`LlmTranslationProvider` marked every unit of the batch as a permanent failure. One problematic sentence left up to 20
harmless units untranslated, and token-budget batching (ADR-0015) made batches larger (#79). The provider cannot tell
which unit caused the refusal without asking again.

Alternatives considered:
- *Send every unit of a refused batch on its own*: n further calls for a batch of n units. Rejected: too expensive for
  large batches.
- *Leave it to `HtmlTranslator`*: it does not know about refusals, and a corrective retry of a refused unit would be
  refused again. Rejected: refusals are a property of chat models, so the LLM provider handles them.

## Decision
- `LlmTranslationProvider` splits a refused batch of more than one unit in halves and sends both, breadth first: the
  halves that are refused again are split in turn, level by level, so the budget is shared by every refused part.
  A refused single unit is not sent again; it fails permanently with a reason naming the refusal.
- The budget is 2 × ⌈log2 n⌉ further calls for a batch of n units, enough to isolate one refused unit. A part that is
  still refused when the budget is spent fails as a whole, as before.
- Parts are not asked once more: the units of a part whose answer cannot be parsed or was cut off become ordinary
  (non-permanent) failures, which `HtmlTranslator` re-sends in its corrective retries. That keeps the bound exact.
- When the chat model throws a `ProviderException` while isolating (e.g. a rate limit), isolating stops: translated
  units are kept, the units not yet resolved fail permanently as refused, with the exception's message, and its tokens
  are counted. A transient error never aborts the document where a plain refusal would not.
- Every call counts: their tokens are summed in `BatchResult::$usage` and reach `TranslationStats`; the parts are the
  provider's calls, not batches, so `TranslationStats::$batches` does not count them.
- Corrective retries do not re-send permanent failures. A corrective retry batch (or a `TextNodesOnly` fallback batch)
  that is refused is isolated in the same way, with its own budget.
- Isolation is on by default; `new LlmTranslationProvider($model, isolateRefusals: false)` turns it off. It does not
  change `cacheId()`: it decides which units are translated, not how.

## Consequences
- One refused sentence costs up to 2 × ⌈log2 n⌉ further calls (6 for a batch of 8, 10 for 20) instead of the whole
  batch; several refused sentences in one batch may leave some harmless units untranslated when the budget runs out.
- Under `TextNodesOnly`, a refused unit's text nodes are sent as a fallback batch, which may be refused and isolated
  again: per refused unit the cost can roughly double.
- When isolating stops on a `ProviderException`, its message is kept in the reason, but its status and vendor code
  are not: `BatchResult` holds reasons as strings.
- Providers of other packages see nothing of this: it stays inside `LlmTranslationProvider`.
- Watch: a model that refuses non-deterministically may refuse a half that contains no problematic unit; its units
  then fail permanently like a refused batch did before.

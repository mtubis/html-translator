# LlmTranslationProvider and the provider contract test case

## Context
ADR-0005, ARCHITECTURE §6.4–§6.5. Combines the chat model port, `PromptBuilder` and `ResponseParser` into a `TranslationProviderInterface` implementation. Corrective retries are driven by the core (`HtmlTranslator`); this provider renders the `Correction`s it receives. This is the second provider implementation, so the shared provider contract suite (#19) is done here.

## Scope
- `Provider\Llm\LlmTranslationProvider` — constructor: `ChatModelInterface`, `PromptBuilder` (default), `ResponseParser` (default); `translate(TranslationBatch): BatchResult`.
  - A `ProviderException` from the chat model propagates (the core decides what it means).
  - A response that cannot be parsed at all → `ProviderException` with the model id; per-unit problems → `BatchResult::$failures`.
  - Content-filter / refusal signals the adapter can recognise → `BatchResult::$permanentFailures` (#20).
- `tests/Contract/TranslationProviderContractTestCase` and its use for `PseudoLocalizationProvider` and `LlmTranslationProvider` on `FakeChatModel` (closes #19).
- End-to-end test: `HtmlTranslator` + `LlmTranslationProvider` + `FakeChatModel` scripted to answer one batch wrongly, then correctly after the correction prompt.

## Acceptance criteria
- [ ] The corrective retry sends the previous answer and problems in the prompt (asserted on the recorded `ChatRequest`).
- [ ] Both providers pass `TranslationProviderContractTestCase`.
- [ ] Every golden fixture translates through `LlmTranslationProvider` with a `FakeChatModel` that answers by pseudo-localising the units it receives (round-trip of prompt → parser), `isComplete() === true`.
- [ ] `composer check` green.

## Out of scope
Real vendors (next issue), token accounting in `TranslationStats` (0.4).

# Token usage and bounded vendor codes on ProviderException

## Context
Review follow-ups of #60 and #52. They change public API, so they belong before 1.0.
- A provider call that ends in a `ProviderException` adds no tokens to `TranslationStats`. A failed call can still be billed, e.g. an answer cut off at the output limit or a refusal after a long prompt, so the stats undercount the cost.
- `ProviderException::$vendorCode` is copied from the vendor's error body without a length or character bound. It is untrusted text that ends up in logs.
- `OpenAiCompatibleChatModel` passes any status of a non-compliant PSR-7 response to `ProviderException::fromHttpResponse()`, which throws `InvalidArgumentException` outside 100–599. The caller then gets an exception that does not describe a provider failure.

## Scope
- `ProviderException::$usage` (`?Provider\TokenUsage`), set by the adapters when the failing response reports usage. `HtmlTranslator` adds it to the stats, on the first call and on retries.
- `vendorCode`: one line, at most 100 characters; anything longer or with control characters is kept as `null` or truncated (`UntrustedText`).
- Both adapters turn a status outside 100–599 into a `ProviderException` without a status code.
- `ChatModelContractTestCase`: a scenario where the vendor reports only input or only output tokens.

## Acceptance criteria
- [ ] Tokens of a failed call that reported usage appear in `stats()`; the docs no longer call the total a lower bound for that case.
- [ ] A vendor code with a line break or 10 kB of text never reaches the exception unbounded.
- [ ] A response with status 0 or 999 yields a `ProviderException`.
- [ ] `composer check` green; `contract-reviewer` sign-off.

## Out of scope
Usage of calls that fail in transport (no response, nothing to report).

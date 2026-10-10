# 0004. Result object instead of exceptions for partial failures

- Status: accepted
- Date: 2026-10-10

## Context
A 200-unit page where one unit fails validation should not lose 199 good translations. Callers still need to know precisely what went wrong.

## Decision
`HtmlTranslator::translate()` returns `TranslatedDocument` (`html()`, `isComplete()`, `issues()`, `stats()`). Unit-level failures are recorded as `Issue`s and resolved by the configured `FallbackStrategy` (`TextNodesOnly`, `KeepOriginal`, `Throw`). Exceptions are reserved for unusable input, unrecoverable provider failures and `FallbackStrategy::Throw`. All library exceptions implement the marker interface `TranslatorException`.

## Consequences
- Callers must check `isComplete()`/`issues()` if they care; documented prominently in README.
- Framework packages can map issues to logs, flash messages or failed-job retries.

# Integration tests assert token usage

## Context
The real-API integration tests check translations but not `stats()` tokens. A vendor that renames its usage fields would go unnoticed, and `inputTokens`/`outputTokens` would silently stay `null`. This is a review follow-up of #60.

## Scope
- In every real-API integration test, assert `inputTokens > 0` and `outputTokens > 0` for providers that report usage, and `null` for those documented not to.
- Document per preset in `docs/providers.md` whether usage is reported.

## Acceptance criteria
- [ ] The integration tests fail when a provider stops reporting usage.
- [ ] `composer check` green; `composer test:integration` green for the configured providers.

## Out of scope
The one-sided usage contract scenario (part of the ProviderException issue).

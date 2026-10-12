# Transport retries with backoff in the chat model adapters

## Context
ARCHITECTURE §6.8 and `ChatModelInterface`'s PHPDoc. Rate limits (429), overload (Anthropic 529) and transient 5xx or network errors fail a whole batch today. The first call of a batch then fails the whole document (ADR-0010). Retries belong in the adapters, through one shared helper, not in the core.

## Scope
- `Provider\Llm\Bridge\RetryPolicy` (`final readonly`):
  - max attempts (default 3);
  - base delay;
  - max delay;
  - jitter;
  - retryable statuses (429, 500, 502, 503, 504, 529);
  - retry on `NetworkExceptionInterface`.
- A shared `@internal` helper that runs a PSR-18 request with that policy and honours `Retry-After` (seconds or HTTP date) up to max delay.
- Sleeping goes through an injectable port (`Sleeper`, with a real and a fake implementation), so tests do not sleep. Time comes from PSR-20 `ClockInterface`: ask before adding `psr/clock`.
- Both adapters take an optional `RetryPolicy` (default: the policy above; `RetryPolicy::none()` disables retries).
- The final failure keeps the last status and vendor code; the message says how many attempts were made.

## Acceptance criteria
- [ ] 429 with `Retry-After: 2` waits 2 s (fake sleeper) and succeeds on the next attempt; 400 and 401 are never retried.
- [ ] Backoff grows exponentially with jitter and is capped; attempts stop at the maximum.
- [ ] No real sleeping in unit or contract tests.
- [ ] `contract-reviewer` sign-off; `composer check` green.

## Open questions (decide before starting)
- **`psr/clock`.** Add it as a runtime dependency, or only inject a `Sleeper`? Recommendation: a `Sleeper` port only, which needs no new dependency.

## Out of scope
Circuit breakers, retrying a batch at the core level, streaming.

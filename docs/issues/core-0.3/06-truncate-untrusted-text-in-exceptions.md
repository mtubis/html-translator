# Truncate untrusted text quoted in exception messages

## Context
Exception messages end up in logs, error trackers and HTTP error pages of host applications. Some messages quote text the library does not control:
- caller input, such as an invalid locale tag or attribute name;
- vendor error messages, as in `ProviderException::fromHttpResponse()`;
- PSR-18 client messages ("Could not reach … : <message>");
- JSON parse errors;
- validation descriptions, which may quote pseudo-tags from the model's answer.

A multi-megabyte or control-character-laden value can flood logs or forge log lines. ADR-0011 already rules that model answers never appear in exception messages; this issue extends that to every quoted untrusted value. Proposed during Core 0.1.

## Scope
- A small internal helper, e.g. `Exception\Quote::untrusted(string $text, int $max = 200): string`:
  - cuts on a UTF-8 character boundary and appends `…` with the original length;
  - replaces control characters and line breaks with visible escapes;
  - replaces invalid UTF-8.

  No interface: it is a single-implementation internal.
- Audit every exception message in `src/` and use the helper wherever a value comes from the caller, a vendor or a model. Known places: `Locale::fromString`, `TranslatableAttributes`, `OpenAiCompatibleChatModel` (transport and JSON errors), `ProviderException::fromHttpResponse()` (vendor message), and `Violation`/`ValidationResult::describe()`. For `describe()`, check what it quotes; it is also sent back to the model, so it must stay useful there.
- `Issue::$message` gets the same treatment, since it is logged by `HtmlTranslator`.

## Acceptance criteria
- [ ] Unit tests for the helper cover:
  - ASCII and multibyte text at the limit, below it and above it;
  - control characters and line breaks;
  - invalid UTF-8.
- [ ] For each audited message, a test passes a 1 MB value with line breaks and asserts the message is bounded and single-line.
- [ ] The audit result is in the PR summary: each message, with "quotes untrusted text, now truncated" or "quotes only trusted values".
- [ ] Corrective-retry problems sent to the model still name the exact pseudo-tags (the existing `StructureValidator` tests stay green).
- [ ] `composer check` green.

## Out of scope
Redacting secrets (already covered by `#[\SensitiveParameter]` and the contract tests), structured logging context.

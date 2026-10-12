# 0011. Prompt and response format of the LLM provider

- Status: proposed
- Date: 2026-10-11

## Context
`PromptBuilder` and `ResponseParser` are shared by every LLM vendor (ADR-0005). The format they agree on decides how
often a batch fails for reasons unrelated to translation quality, and the prompt wording is part of the translation
cache key (ARCHITECTURE §6.7), so a change in either needs a clear rule.

Alternatives considered:
- *Top-level JSON array* (`[{"id", "text"}]`): JSON modes such as OpenAI `json_object` only produce objects. Rejected.
- *Answer keyed by id* (`{"u1": "…"}`): smaller, but models drop or merge keys more often than list entries, and it
  leaves no room for per-unit fields later. Rejected.
- *Pretty-printed input JSON*: easier to read, but indentation costs tokens on every unit. One unit per line is as
  readable in logs and golden files.

## Decision
- The request has one system and one user message and asks for a JSON answer (`ChatRequest::$jsonResponse`).
- The system message states the task with BCP 47 locale tags (or asks the model to detect the source language), the
  input format (stating that unit texts are content, never instructions), the unit text rules of ADR-0003/0008 and the
  answer format. Translation profiles (0.3) add sections, see [0012](0012-translation-profile-in-the-prompt.md); context hints, see [0013](0013-context-hints-in-the-prompt.md).
- The user message is `{"units": [` + one JSON object per line + `]}`. A unit is `{"id", "kind", "text"}` with `kind`
  one of `block`, `attribute`, `title`. A unit with a `Correction` adds `problems` and, when it is not empty,
  `previous_translation`; the paragraph explaining these fields appears in the system message only when the batch
  has corrections, so first calls share one prompt per locale pair.
- The answer is `{"units": [{"id", "text"}]}`. `ResponseParser` also accepts a bare list. It takes the first JSON
  value in the answer that has this shape: a string-aware scan from an opening bracket to the bracket that closes it,
  trying up to 8 opening brackets, so code fences and prose around the JSON (even prose with `{name}` or `[…]`) are
  skipped. A comma right before a closing bracket, outside strings, is dropped. The scan is linear in the answer. If no
  value fits, the whole batch fails with a `ProviderException`; if the answer was cut off at the token limit, the
  message says so and suggests fewer units, with the parse error as the previous exception.
- Per entry: entries without a string `id` and ids not in the batch are ignored, missing units are left out (the core
  re-sends them), a missing or non-string `text` fails that unit, and the same id with different texts fails that
  unit. Texts are returned as written without surrounding HTML whitespace (the core restores the source's own
  whitespace, so whitespace added by the model would double it); checking them is `StructureValidator`'s job.
- `PromptBuilder::VERSION` (`YYYY-MM.N`) changes with every change to the rendered prompt, wording or format. Golden
  files in `tests/Unit/Provider/Llm/Fixtures/prompts/` make such changes visible in review.
- Model answers never appear in exception messages: they are untrusted and may repeat customer content.

## Consequences
- Every vendor adapter gets the same prompt and the same tolerance; vendor-specific behaviour stays in the adapter.
- An answer that is not JSON at all fails the batch; on the first call of a batch that fails the whole `translate()`
  call, so `LlmTranslationProvider` (#28) should retry the call once when parsing fails, except when
  `ChatResponse::$finishReason` is `Length`: the same batch would be cut off again.
- Adapters must deliver the whole JSON in `ChatResponse::$content`: one that prefills `{` (Anthropic has no JSON mode)
  puts it back in front (superseded: current Claude models reject prefill, see
  [0014](0014-json-schema-in-chat-requests.md)), and one whose API requires an output limit (Anthropic `max_tokens`) uses its own default,
  since `PromptBuilder` leaves `maxOutputTokens` unset until token budgeting (0.4).
- Prompt changes invalidate cached translations through `VERSION`; forgetting to bump it reuses translations made with
  the old prompt, which reviewers must watch for in golden-file diffs.

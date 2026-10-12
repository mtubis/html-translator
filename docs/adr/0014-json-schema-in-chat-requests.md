# 0014. JSON schema in chat requests

- Status: proposed; amends [0011](0011-prompt-and-response-format-of-the-llm-provider.md) (consequences on adapters
  without a JSON mode)
- Date: 2026-10-12

## Context
ADR-0011 assumed that an adapter for an API without a JSON mode (Anthropic) would prefill the assistant turn with
`{`. The Claude Messages API now rejects assistant prefill with HTTP 400 on every current model; the documented way to
get JSON is structured output (`output_config.format: {"type": "json_schema", "schema": …}`), which constrains the
answer to a schema and guarantees valid JSON. A schema is specific to the caller's answer format, which only
`PromptBuilder` knows; the port (`ChatRequest`) only had `jsonResponse: bool`.

Alternatives considered:
- *Prompt only*: no enforcement, rely on the instruction and the lenient `ResponseParser`. Works, but wastes the
  guarantee the API offers and makes Anthropic the only vendor without any JSON constraint. Rejected.
- *Schema hard-coded in the Anthropic adapter*: the adapter would know the translation answer format, against
  ADR-0005 (adapters are HTTP mappings only). Rejected.

## Decision
- `ChatRequest` gets an optional `?JsonSchema $jsonSchema`, allowed only with `jsonResponse: true`. `JsonSchema` is a
  value object built from JSON text (`JsonSchema::fromJson()`), whose top level describes an object; it keeps compact
  JSON so no untyped array crosses the port and empty objects stay objects.
- `PromptBuilder` sends the ADR-0011 answer format as a schema: an object with a required `units` list of objects with
  required string `id` and `text`, `additionalProperties: false` throughout. This is part of the request, so it came
  with a new `PromptBuilder::VERSION` (`2026-10.4`); the rendered messages are unchanged.
- Adapters with structured outputs send the schema (`AnthropicChatModel`: `output_config.format`). Adapters without
  ignore it and keep their JSON mode (`OpenAiCompatibleChatModel`: `response_format: json_object`). A JSON request
  without a schema is not enforced by `AnthropicChatModel`; the prompt asks for JSON.
- `ChatModelContractTestCase` sends a schema; `RecordedChatRequest::$jsonSchema` reports what the adapter sent, and an
  adapter that sends one must send the requested one.
- No adapter prefills the assistant turn.

## Consequences
- `ResponseParser` stays lenient: adapters without a schema still get prose or code fences around the JSON.
- The answer schema and the prompt's description of the answer must stay in sync; both live in `PromptBuilder`, and
  a test checks the schema's shape.
- `OpenAiCompatibleChatModel` could later send the schema as `response_format: {"type": "json_schema", …}` where a
  server supports it (LM Studio supports only that); a separate change.
- Structured outputs and refusals: a refused answer (`stop_reason: "refusal"`) may not match the schema; the adapter
  reports it as `FinishReason::Refusal`, which the provider treats as a permanent failure.

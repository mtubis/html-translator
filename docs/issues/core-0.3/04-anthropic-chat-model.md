# AnthropicChatModel on PSR-18 (Claude Messages API)

## Context
ADR-0002 (PSR-18/17 instead of SDKs), ADR-0005, ADR-0011 (consequences for adapters without a JSON mode or with a required output limit), skill `add-translation-provider`, and `OpenAiCompatibleChatModel` as the reference adapter.

## Scope
- `Provider\Llm\Bridge\Anthropic\AnthropicChatModel implements ChatModelInterface`. Constructor:
  - PSR-18 `ClientInterface`;
  - PSR-17 `RequestFactoryInterface` and `StreamFactoryInterface`;
  - `AnthropicConfig`.
- `AnthropicConfig`: `final readonly`. Fields:
  - base URL (default: the public API);
  - model, required, with no default model id in code;
  - `#[\SensitiveParameter]` API key;
  - API version header;
  - default `max_tokens`, since the API requires one and `PromptBuilder` leaves it unset (ADR-0011);
  - timeout;
  - extra headers.

  Validation follows `OpenAiCompatibleConfig`.
- Request mapping:
  - system messages go into the top-level `system` field;
  - user and assistant messages map to `messages`;
  - `temperature` above 1 is rejected (Anthropic accepts 0–1, the port 0–2; see Decided);
  - `max_tokens`.
  - JSON mode: Anthropic has no `json_object` mode. Use the mechanism the current API offers (see open questions), so that `ChatResponse::$content` always holds the whole JSON.
- Response mapping:
  - `content` text blocks are concatenated;
  - `usage.input_tokens` and `usage.output_tokens`;
  - `model`;
  - `stop_reason` → `FinishReason`: `end_turn` → Stop, `max_tokens` → Length, `refusal` → ContentFilter/Refusal, others → Other.
- Errors:
  - non-2xx → `ProviderException::fromHttpResponse()` with `error.type`; this covers the `overloaded_error` status 529;
  - a malformed body → `ProviderException`;
  - PSR-18 exceptions are wrapped.

  The API key never appears in messages or traces.
- Recorded fixtures in `tests/Contract/Fixtures/Anthropic/`: success, a refusal, `max_tokens`, 400, 401, 429, 500, 529 and a malformed body. A contract test extends `ChatModelContractTestCase`.
- An integration test with `#[Group('integration')]`, skipped without `HTML_TRANSLATOR_ANTHROPIC_API_KEY`. Add the variable to `.env.dist`. Add a `--provider=anthropic` option to `tools/eval`.
- Update `docs/providers.md` and the README provider table.

## Acceptance criteria
- [ ] The adapter passes `ChatModelContractTestCase` against all recorded fixtures.
- [ ] Config validation and request mapping are covered by table tests: system message placement, `max_tokens` default, headers, JSON-mode mechanism.
- [ ] The no-JSON-mode handling is covered: the content is a whole JSON object even when the mechanism splits it.
- [ ] No vendor SDK or concrete HTTP client in `src/` (ADR-0002); `contract-reviewer` sign-off.
- [ ] `composer check` green.

## Open questions (verify before starting)
- **JSON output mechanism.** Check the current Messages API docs (load the `claude-api` skill) for what applies to current models: assistant prefill of `{` (ADR-0011 assumes it; recent models may reject prefill), structured outputs / JSON schema, or a forced tool call. If prefill is not the answer, record the chosen mechanism in an ADR amending ADR-0011's consequences.
- **Example model id.** Which model id appears in the docs examples? Take it from the `claude-api` skill at implementation time; no default in code.

## Decided
- A temperature above 1 throws `InvalidArgumentException` in the adapter; a value is never silently changed.

## Out of scope
Streaming, prompt caching, extended thinking, transport retries with backoff (0.4), Bedrock/Vertex endpoints.

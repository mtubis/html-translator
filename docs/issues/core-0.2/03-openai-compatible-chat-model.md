# OpenAiCompatibleChatModel on PSR-18 (OpenAI, Ollama, vLLM, LM Studio, Gemini)

## Context
ADR-0002 (PSR-18/17 instead of SDKs), ADR-0005, skill `add-translation-provider`. One adapter for the OpenAI Chat Completions shape covers OpenAI, Ollama, vLLM, LM Studio and Gemini's OpenAI-compatible endpoint.

## Scope
- `Provider\Llm\Bridge\OpenAiCompatible\OpenAiCompatibleChatModel implements ChatModelInterface` — constructor: PSR-18 `ClientInterface`, PSR-17 `RequestFactoryInterface` + `StreamFactoryInterface`, `OpenAiCompatibleConfig`.
- `OpenAiCompatibleConfig` — `final readonly`: base URL, model, `#[\SensitiveParameter]` API key (optional for local servers), timeout, extra headers; named constructors with presets: `openAi()`, `ollama()`, `vllm()`, `lmStudio()`, `gemini()`.
- Request mapping: messages, temperature, `max_tokens`, `response_format: {"type": "json_object"}` when `jsonResponse` (configurable per preset where unsupported); response mapping incl. `usage` tokens and `model`.
- Errors: non-2xx → `ProviderException::fromHttpResponse()` with the vendor `error.code`/`error.type`; malformed JSON body → `ProviderException`; PSR-18 `ClientExceptionInterface` wrapped. API key never in messages, logs or exception traces.
- Recorded fixtures in `tests/Contract/Fixtures/OpenAiCompatible/` (success, code-fenced JSON, 400, 401, 429, 500, malformed body), contract test extending `ChatModelContractTestCase` with a fake PSR-18 client.
- `docs/providers.md` and a README provider table.

## Acceptance criteria
- [ ] Adapter passes `ChatModelContractTestCase` against all recorded fixtures.
- [ ] Presets produce the documented base URL / auth header / JSON-mode settings (table test).
- [ ] No vendor SDK or concrete HTTP client in `src/` (ADR-0002); `contract-reviewer` sign-off.
- [ ] `composer check` green.

## Open questions (decide before starting)
- Runtime dependency `psr/http-message` (types of PSR-18/17 requests and responses; only a PSR interface package).
- Dev dependency for a PSR-7/17 implementation in tests (e.g. `nyholm/psr7`) and whether to use `php-http/mock-client` or a hand-written fake client.

## Decided
- Transport retries with backoff for 429/5xx are not part of this issue: 0.2 maps errors only, retries come with the shared retry helper in 0.4 (ARCHITECTURE §6.8, ROADMAP).

## Out of scope
`AnthropicChatModel` (0.3), streaming, transport retries (0.4).

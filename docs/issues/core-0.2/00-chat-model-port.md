# Chat model port: ChatModelInterface, ChatRequest/ChatResponse, FakeChatModel, contract test case

## Context
ADR-0005 (two ports), ARCHITECTURE §5. The LLM provider (next issues) talks to every vendor through one small port; vendor adapters are only HTTP mappings. This issue defines the port and the test tooling every adapter must pass — no network yet.

## Scope
- `Provider\Llm\ChatModelInterface::complete(ChatRequest): ChatResponse`.
- `Provider\Llm\ChatRequest` — `final readonly`: list of `ChatMessage` (`ChatRole` enum: `System`, `User`, `Assistant`; non-empty content), `?float $temperature`, `bool $jsonResponse` (ask the model for a JSON object), optional `?int $maxOutputTokens`. Validated in the constructor.
- `Provider\Llm\ChatResponse` — `final readonly`: `string $content`, `?int $inputTokens`, `?int $outputTokens`, `string $model` (as reported by the vendor).
- `Provider\Llm\Testing\FakeChatModel` — scripted responses (queue of `ChatResponse` or `ProviderException`), records requests; deterministic, for unit and contract tests.
- `tests/Contract/ChatModelContractTestCase` — abstract base that every adapter must pass with a fake PSR-18 transport: maps messages and roles, honours `jsonResponse`, surfaces token counts when present, turns 4xx/5xx and malformed bodies into `ProviderException` (status and vendor code via `fromHttpResponse()`), never puts credentials into exception messages.
- PHPDoc on the interface: adapters do transport retries themselves (ARCHITECTURE §6.8); the core never retries a call that threw.

## Acceptance criteria
- [ ] `FakeChatModel` passes `ChatModelContractTestCase` (with a trivial transport stand-in) — proves the base class is usable.
- [ ] Value objects reject invalid states (empty message list, empty content, temperature outside 0–2, non-positive max tokens) with `Exception\InvalidArgumentException`.
- [ ] `Provider\Llm` depends only on `Domain`, `Exception`, `Provider` and PSR interfaces; no vendor SDK, no concrete HTTP client (ADR-0002).
- [ ] `composer check` green.

## Out of scope
Prompt construction and response parsing (next issue), any real vendor adapter, transport retry helper (0.4).

# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Security
- Chat model adapters no longer keep the PSR-18 client exception as the cause of a transport `ProviderException`: it
  holds or recorded the request with the API key header. The cause names the client exception's type and its redacted
  message, and parameters that receive secrets are `#[\SensitiveParameter]`, so dumps show no key even with
  `zend.exception_ignore_args` off (#52).
- Messages that quote untrusted text (caller input, configuration, vendor and transport errors, model output) are
  bounded and kept on one line (`Exception\UntrustedText`, #43).

### Changed
- The chat model configs share their key, header and base URL rules (`Provider\Llm\Bridge\HttpSecrets`, #53).
  `AnthropicConfig` now also rejects a base URL with whitespace or control characters, and
  `OpenAiCompatibleConfig::redact()` removes the key whatever its length.
- `ChatModelContractTestCase` has a new scenario, `ChatScenarioKind::NetworkError` / `ChatScenario::networkError()`.
  Contract tests of third-party adapters that match the kinds exhaustively must handle it (#52).

### Added
- Transport retries in both chat model adapters: `Retrier`, `RetryPolicy` (backoff with jitter, `Retry-After`) and the
  `Sleeper` port with `UsleepSleeper` (#61).
- Project skeleton, architecture documentation and ADRs 0001–0010.
- Domain value objects: `Locale`, `LocalePair`, `UnitId`, `InlineTag`, `TranslationUnit`, `UnitText`.
- HTML pipeline: `HtmlLoader`, `SkipRules`, `TranslatableAttributes`, `Segmenter`, `InlineEncoder`, `Reassembler`.
- `StructureValidator` with model-facing violation messages.
- Provider port (`TranslationProviderInterface`, `TranslationBatch`, `BatchResult`, `Correction`) and
  `PseudoLocalizationProvider`.
- `HtmlTranslator` orchestration with corrective retries, fallback strategies, issues, stats and progress.
- Golden fixture harness and seed corpus.
- `BatchResult::$permanentFailures` for units that must not be retried; `ProviderException::fromHttpResponse()` with
  `statusCode` and `vendorCode`.
- Chat model port (`Provider\Llm`: `ChatModelInterface`, `ChatRequest`, `ChatMessage`, `ChatRole`, `ChatResponse`,
  `FinishReason`), `FakeChatModel` and the reusable `ChatModelContractTestCase`.
- `PromptBuilder` and `ResponseParser`: the versioned prompt and the JSON unit format of the LLM provider (ADR-0011).
- `LlmTranslationProvider` (prompt, chat model, parser; one retry for an unparsable answer, refused batches fail
  permanently) and the reusable `TranslationProviderContractTestCase`.
- `OpenAiCompatibleChatModel` and `OpenAiCompatibleConfig` with presets for OpenAI, Gemini, Ollama, vLLM and LM Studio;
  `docs/providers.md`. Runtime dependency `psr/http-message`.
- Integration tests for OpenAI-compatible providers (`composer test:integration`, configured from `.env`) and
  `tools/eval`, a structural report of a real model over the golden fixtures.

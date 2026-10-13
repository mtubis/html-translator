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
- `OpenAiCompatibleConfig::$jsonMode` is an `OpenAiCompatible\JsonMode` instead of a `bool` (`true` →
  `JsonMode::JsonObject`, `false` → `JsonMode::None`). `openAi()` uses `JsonSchema` and `lmStudio()` `JsonSchemaOnly`:
  the answer schema is sent as `response_format: json_schema` (strict), so the answer shape is enforced.
  A schema of your own sent through these presets must follow OpenAI's strict rules (every property in `required`,
  `additionalProperties: false`), or the server answers HTTP 400. OpenAI models without structured outputs need
  `openAi($key, $model, JsonMode::JsonObject)` (#54).
- `IssueType` has a new case, `TermViolation` (#47). Code that matches `IssueType` exhaustively must handle it.
- The do-not-translate section of the prompt lists only the terms that occur in the batch, like the glossary; with 500
  terms that saves about 2,300 tokens per call. `PromptBuilder::VERSION` is 2026-10.5, so unit-cache entries made with
  the previous prompt are not reused (#48).
- `AnthropicConfig::modelId()` names the settings that change answers: `+effort=<level>` and `+fallbacks` (e.g.
  `anthropic:claude-opus-5-5+effort=low`). The model id is part of the unit cache key and appears in exception
  messages and eval reports (#59).
- `tools/eval` takes token counts from `TranslationStats`; a fixture that fails with an error shows no tokens, and the
  summary line says how many fixtures it leaves out (#60).
- Units are no longer cut into fixed chunks of `batchSize`: a batch also closes at `tokenBudget`, so documents with
  long units need more provider calls, which shows in `stats()->batches` and the `onProgress` totals (#58).
- The chat model configs share their key, header and base URL rules (`Provider\Llm\Bridge\HttpSecrets`, #53).
  `AnthropicConfig` now also rejects a base URL with whitespace or control characters, and
  `OpenAiCompatibleConfig::redact()` removes the key whatever its length.
- `ChatModelContractTestCase` has a new scenario, `ChatScenarioKind::NetworkError` / `ChatScenario::networkError()`.
  Contract tests of third-party adapters that match the kinds exhaustively must handle it (#52).

### Added
- Term check: a translation that changes a do-not-translate term, or a glossary term whose target equals its source,
  gets an `IssueType::TermViolation` issue naming the terms. The translation is used anyway (no retry, no fallback,
  no extra call); glossary entries that translate their term are not checked, since targets may be inflected
  (`Validation\TermChecker`, ADR-0017, #47).
- Optional unit cache: `new HtmlTranslator($provider, cache: $psr6Pool)`. Units are looked up before batching and
  validated like provider answers; validated translations are stored after each batch, failures and fallback output
  never. Only providers implementing `Provider\CacheableProviderInterface` (`cacheId()`) are cached;
  `LlmTranslationProvider` and `PseudoLocalizationProvider` are. `TranslationStats::$cacheHits` counts the hits and
  `TranslationProfile::fingerprint()` identifies a profile. No expiry is set; configure it on the pool (ADR-0016, #59).
- `TranslationProviderContractTestCase` checks that a cacheable provider has a stable, single-line cache id (#59).
- Token usage in the stats: `TranslationStats::$inputTokens` / `$outputTokens`, summed over every provider call of a
  document (corrective retries and the text-node fallback included) and null while no call reported a count. Providers
  report it in `BatchResult::$usage` (`Provider\TokenUsage`); `LlmTranslationProvider` fills it from the chat
  responses. A provider call that ends in a `ProviderException` adds no tokens (#60).
- Batches are sized by an approximate token budget: `TranslationOptions::$tokenBudget` (default 2,000), `Batcher`
  and the `TokenEstimatorInterface` port with `CharacterTokenEstimator`; `batchSize` stays as the upper bound of units
  per batch, and `HtmlTranslator` takes an optional `batcher` (ADR-0015, #58).
- Transport retries in both chat model adapters: `Retrier`, `RetryPolicy` (backoff with jitter, `Retry-After`) and the
  `SleeperInterface` port with `UsleepSleeper`, and `FakeSleeper` for tests (#61).
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

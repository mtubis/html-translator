# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.4.0] - 2026-10-13

Production readiness: token-budget batching, a unit cache, token usage, transport retries and the usage guide.

### Security
- Chat model adapters no longer keep the PSR-18 client exception as the cause of a transport `ProviderException`: it
  holds or recorded the request with the API key header. The cause names the client exception's type and its redacted
  message, and parameters that receive secrets are `#[\SensitiveParameter]`, so dumps show no key even with
  `zend.exception_ignore_args` off (#52).

### Changed
- `OpenAiCompatibleConfig::$jsonMode` is an `OpenAiCompatible\JsonMode` instead of a `bool` (`true` →
  `JsonMode::JsonObject`, `false` → `JsonMode::None`). `openAi()` uses `JsonSchema` and `lmStudio()` `JsonSchemaOnly`:
  the answer schema is sent as `response_format: json_schema` (strict), so the answer shape is enforced.
  A schema of your own sent through these presets must follow OpenAI's strict rules (every property in `required`,
  `additionalProperties: false`), or the server answers HTTP 400. OpenAI models without structured outputs need
  `openAi($key, $model, JsonMode::JsonObject)` ([ADR-0014](docs/adr/0014-json-schema-in-chat-requests.md), #54).
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
- [Usage guide](docs/usage.md) and a README with OpenAI and Anthropic setups, a profile, a cache and result
  handling; every snippet of both is run by the test suite (`tests/Documentation`), so the examples cannot rot.
  Release checklist in [docs/RELEASING.md](docs/RELEASING.md) (#62).
- `AnthropicConfig::$betas`: further `anthropic-beta` values (Anthropic beta names), sent with the refusal fallback beta in
  one header. `modelId()` names them as `+betas=<sorted list>`, so changing them starts a fresh unit cache (#55).
- Term check: a translation that changes a do-not-translate term, or a glossary term whose target equals its source,
  gets an `IssueType::TermViolation` issue naming the terms. The translation is used anyway (no retry, no fallback,
  no extra call); glossary entries that translate their term are not checked, since targets may be inflected
  (`Validation\TermChecker`, [ADR-0017](docs/adr/0017-term-checks-after-translation.md), #47).
- Optional unit cache: `new HtmlTranslator($provider, cache: $psr6Pool)`. Units are looked up before batching and
  validated like provider answers; validated translations are stored after each batch, failures and fallback output
  never. Only providers implementing `Provider\CacheableProviderInterface` (`cacheId()`) are cached;
  `LlmTranslationProvider` and `PseudoLocalizationProvider` are. `TranslationStats::$cacheHits` counts the hits and
  `TranslationProfile::fingerprint()` identifies a profile. No expiry is set; configure it on the pool ([ADR-0016](docs/adr/0016-unit-cache-and-cacheable-providers.md), #59).
- `TranslationProviderContractTestCase` checks that a cacheable provider has a stable, single-line cache id (#59).
- Token usage in the stats: `TranslationStats::$inputTokens` / `$outputTokens`, summed over every provider call of a
  document (corrective retries and the text-node fallback included) and null while no call reported a count. Providers
  report it in `BatchResult::$usage` (`Provider\TokenUsage`); `LlmTranslationProvider` fills it from the chat
  responses. A provider call that ends in a `ProviderException` adds no tokens (#60).
- Batches are sized by an approximate token budget: `TranslationOptions::$tokenBudget` (default 2,000), `Batcher`
  and the `TokenEstimatorInterface` port with `CharacterTokenEstimator`; `batchSize` stays as the upper bound of units
  per batch, and `HtmlTranslator` takes an optional `batcher` ([ADR-0015](docs/adr/0015-token-budget-batching.md), #58).
- Transport retries in both chat model adapters: `Retrier`, `RetryPolicy` (backoff with jitter, `Retry-After`) and the
  `SleeperInterface` port with `UsleepSleeper`, and `FakeSleeper` for tests (#61).

## [0.3.0] - 2026-10-12

Steerability: translation profiles, context hints and Claude. Not tagged; 0.4.0 is the first release.

### Added
- Translation profiles: `Profile\TranslationProfile` with the presets `technical()`, `marketing()` and `legal()`,
  `Formality`, `TermPolicy`, `Glossary` / `GlossaryEntry` and do-not-translate terms; `TranslationOptions::$profile`
  passes one to every call of a document. Glossary entries are sent only for the batches whose text contains them
  ([ADR-0012](docs/adr/0012-translation-profile-in-the-prompt.md), #37, #38). See [docs/profiles.md](docs/profiles.md).
- Context hints: every batch carries the page title and the section heading of each unit (`Provider\BatchContext`),
  and `LlmTranslationProvider` puts them in the prompt ([ADR-0013](docs/adr/0013-context-hints-in-the-prompt.md), #39, #40).
- `AnthropicChatModel`, `AnthropicConfig` and `AnthropicEffort` for the Claude Messages API, with structured output
  and optional refusal fallbacks; `ChatRequest::$jsonSchema` / `Provider\Llm\JsonSchema` carry the answer schema
  ([ADR-0014](docs/adr/0014-json-schema-in-chat-requests.md), #41).

### Fixed
- Fragments that start with table parts (`<tr>`, `<td>`, `<thead>`, `<col>`, …) keep their elements: they are parsed
  in the table context they belong in (#42).

### Security
- Messages that quote untrusted text (caller input, configuration, vendor and transport errors, model output) are
  bounded and kept on one line (`Exception\UntrustedText`, #43).

## [0.2.0] - 2026-10-12

First real models. Not tagged.

### Added
- Chat model port (`Provider\Llm`: `ChatModelInterface`, `ChatRequest`, `ChatMessage`, `ChatRole`, `ChatResponse`,
  `FinishReason`), `FakeChatModel` and the reusable `ChatModelContractTestCase` ([ADR-0005](docs/adr/0005-two-ports-provider-and-chat-model.md), #26).
- `PromptBuilder` and `ResponseParser`: the versioned prompt and the JSON unit format of the LLM provider
  ([ADR-0011](docs/adr/0011-prompt-and-response-format-of-the-llm-provider.md), #27).
- `LlmTranslationProvider` (prompt, chat model, parser; one retry for an unparsable answer, refused batches fail
  permanently) and the reusable `TranslationProviderContractTestCase` (#19, #28).
- `OpenAiCompatibleChatModel` and `OpenAiCompatibleConfig` with presets for OpenAI, Gemini, Ollama, vLLM and LM Studio,
  on any PSR-18 client ([ADR-0002](docs/adr/0002-psr-http-instead-of-vendor-sdks.md)); [docs/providers.md](docs/providers.md). Runtime dependency `psr/http-message`
  (#29).
- Integration tests for real providers (`composer test:integration`, configured from `.env`) and `tools/eval`, a
  structural report of a real model over the golden fixtures (#30).

## [0.1.0] - 2026-10-11

The pipeline without any model. Not tagged.

### Added
- Public API: `HtmlTranslator::translate()`, `TranslationOptions`, `TranslatedDocument` with `html()`, `isComplete()`,
  `issues()` and `stats()`, `Issue` / `IssueType`, `TranslationStats`, `Progress` and `FallbackStrategy`
  ([ADR-0004](docs/adr/0004-result-object-for-partial-failures.md), [ADR-0010](docs/adr/0010-public-api-of-core-0-1.md), #8).
- Domain value objects: `Locale`, `LocalePair`, `UnitId`, `InlineTag`, `TranslationUnit`, `UnitText` (#2).
- HTML pipeline on PHP 8.4's `Dom\HTMLDocument` ([ADR-0006](docs/adr/0006-php-84-native-html5-parser.md)): `HtmlLoader` (#3), `SkipRules` and
  `TranslatableAttributes` (#4), `Segmenter` and `InlineEncoder` with inline markup as numbered pseudo-tags
  ([ADR-0003](docs/adr/0003-inline-markup-as-numbered-pseudo-tags.md), [ADR-0007](docs/adr/0007-inline-tag-mapping-lives-in-html-layer.md), [ADR-0008](docs/adr/0008-unit-text-escaping.md), #5), `StructureValidator` with model-facing violation messages (#6) and
  `Reassembler` (#7). Form data (`<textarea>` content, submitted `<input>` values) is not translated (#15).
- Provider port (`TranslationProviderInterface`, `TranslationBatch`, `BatchResult`, `Correction`),
  `PseudoLocalizationProvider` and `HtmlTranslator` orchestration with corrective retries, fallback strategies,
  issues, stats and progress (#8).
- `BatchResult::$permanentFailures` for units that must not be retried; `ProviderException::fromHttpResponse()` with
  `statusCode` and `vendorCode` (#20). Corrective retries tell the provider which units its answer left out (#21).
- Golden fixture harness and seed corpus (#9); project skeleton, architecture documentation and
  [ADR-0001](docs/adr/0001-record-architecture-decisions.md)–[ADR-0010](docs/adr/0010-public-api-of-core-0-1.md) (#1). Lenient decoding of model output is described in [ADR-0009](docs/adr/0009-lenient-decoding-of-model-output.md).

[Unreleased]: https://github.com/mtubis/html-translator/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/mtubis/html-translator/releases/tag/v0.4.0

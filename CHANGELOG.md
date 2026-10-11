# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
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

# Roadmap

Each milestone is a GitHub milestone; each bullet is one issue = one Claude Code session = one PR.
Issue bodies are drafted in [`docs/issues/core-0.1/`](issues/core-0.1/), [`docs/issues/core-0.2/`](issues/core-0.2/), [`docs/issues/core-0.3/`](issues/core-0.3/) and [`docs/issues/core-0.4/`](issues/core-0.4/) and can be created with `scripts/create-issues.sh`.

## Core 0.1 — pipeline without any LLM
Goal: any HTML fixture round-trips through segmentation → pseudo-localization → validation → reassembly with valid structure.

0. Bootstrap tooling, `composer check` green
1. Domain value objects (`Locale`, `LocalePair`, `UnitId`, `InlineTag`, `TranslationUnit`)
2. `HtmlLoader`: load/serialize documents and fragments with `Dom\HTMLDocument`, round-trip tests
3. `SkipRules` and `TranslatableAttributes`
4. `Segmenter` + `InlineEncoder` (block units, pseudo-tags, attribute units)
5. `StructureValidator`
6. `Reassembler`
7. `PseudoLocalizationProvider`, provider port, `HtmlTranslator` orchestration, `TranslatedDocument`, `Issue`, `FallbackStrategy`
8. Golden fixture harness + seed corpus (15–20 fixtures)

## Core 0.2 — first real models
- `ChatModelInterface`, `ChatRequest`/`ChatResponse`, `FakeChatModel`, contract test case
- `PromptBuilder` (versioned template) and `ResponseParser`
- `LlmTranslationProvider` with corrective retries
- `OpenAiCompatibleChatModel` (OpenAI, Ollama, vLLM, LM Studio, Gemini compat endpoint) on PSR-18
- Integration test group + `tools/eval` first version

## Core 0.3 — steerability
- `TranslationProfile`, `TermPolicy`, `Formality`, `Glossary`, presets (`technical()`, `marketing()`, `legal()`)
- `AnthropicChatModel`
- Context hints in batches (title, neighbouring headings)
- Follow-ups from 0.1: table-part fragments, truncated untrusted text in exception messages

## Core 0.4 — production readiness
- `Batcher` with token budget, `TokenEstimator`
- PSR-6 unit cache, `TranslationStats`, `onProgress`
- Transport retries with backoff in adapters
- README with real examples, [usage guide](usage.md), 0.4.0 release on Packagist ([RELEASING](RELEASING.md))

## 1.0 — API freeze
- Public API review (deprecations removed), Infection MSI ≥ 85 %, docs complete.

## Then
- `html-translator-bundle` (Symfony): config tree (providers, profiles), DI, console command, Messenger handler, optional `symfony/ai` chat-model bridge.
- `html-translator-laravel`: service provider, config, facade, Artisan command, queued job, optional Prism bridge.
- Drupal module (optional): formatted-text fields translated field by field via the core; evaluate the Drupal AI module's translation submodule first.

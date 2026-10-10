# Architecture

> Status: living document. Decisions that change this file are recorded as ADRs in [`docs/adr/`](adr/).

## 1. Purpose

`html-translator` translates HTML documents and fragments with large language models while guaranteeing that:

1. **The output is valid HTML** with the same element structure as the input.
2. **HTML semantics are preserved** — inline markup (`<strong>`, `<a href>`, `<code>`, `<img>` …) stays attached to the right words, non-translatable regions are never touched.
3. **Translation is steerable** — tone, formality, domain, glossary and term policy (e.g. "keep English technical terms") are first-class configuration, not ad-hoc prompt strings.
4. **The model is replaceable** — OpenAI, Anthropic, Gemini, self-hosted models (Ollama, vLLM, LM Studio) or a non-LLM engine sit behind one port.

The library is framework-agnostic. It depends only on PHP and PSR interfaces. Symfony and Laravel integrations live in separate packages that depend on this one.

## 2. Non-goals

- No persistence, no queues, no HTTP endpoints — those belong to the framework packages or the host application.
- No machine-translation quality estimation beyond structural validation (evaluation tooling lives in `tools/eval`, not in `src/`).
- No support for PHP < 8.4 (see [ADR-0006](adr/0006-php-84-native-html5-parser.md)).
- Full tactical DDD (aggregates, repositories, domain events) is out of scope. We use a **ubiquitous language**, **immutable value objects** and **ports & adapters** — nothing more.

## 3. Ubiquitous language

| Term | Meaning |
|---|---|
| **Document** | The HTML input: a full document (`<!DOCTYPE html>…`) or a fragment. |
| **Translation unit** (`TranslationUnit`) | The smallest piece of text sent to the model: the inline content of one block element, or one translatable attribute value. Has a stable id within a document. |
| **Inline tag** (`InlineTag`) | An inline element inside a unit, replaced by a numbered pseudo-tag before translation (see [ADR-0003](adr/0003-inline-markup-as-numbered-pseudo-tags.md)). |
| **Skip rule** | A rule deciding that an element (and its subtree) is not translated: `script`, `style`, `code`, `pre`, `kbd`, `samp`, `var`, `[translate="no"]`, `.notranslate`, configurable. |
| **Locale / LocalePair** | BCP 47 language tags for source and target. Source may be "auto". |
| **Translation profile** (`TranslationProfile`) | Everything that steers *how* text is translated: domain, register/formality, term policy, glossary, do-not-translate terms, free-form instructions. |
| **Term policy** (`TermPolicy`) | Enum controlling loan words: `TranslateAll`, `PreferOriginalTechnicalTerms`, `KeepOriginal`… |
| **Batch** (`TranslationBatch`) | A group of units sent to a provider in one call, sized by a token budget. |
| **Provider** | Anything implementing `TranslationProviderInterface`: turns a batch into translated units. |
| **Chat model** | An LLM endpoint behind `ChatModelInterface`. Used by the LLM-based provider. |
| **Issue** (`Issue`) | A non-fatal problem recorded during translation (unit failed validation, fallback applied, …). |
| **Fallback strategy** | What to do with a unit that still fails after corrective retries. |

Use these names in code, tests, docs, commit messages and prompts to coding agents.

## 4. Public API (stable surface)

```php
$translator = new HtmlTranslator(
    provider: $provider,                 // TranslationProviderInterface (required)
    cache: $psr6Pool,                    // ?CacheItemPoolInterface
    logger: $psr3Logger,                 // ?LoggerInterface
    // optional collaborators with sensible defaults:
    // segmenter, reassembler, validator, skipRules, clock
);

$result = $translator->translate(
    html: $html,
    target: Locale::fromString('pl'),
    options: new TranslationOptions(
        source: Locale::fromString('en'),           // null = auto-detect by the model
        profile: TranslationProfile::technical()
            ->withTermPolicy(TermPolicy::PreferOriginalTechnicalTerms)
            ->withGlossary(Glossary::fromArray(['pull request' => 'pull request'])),
        fallback: FallbackStrategy::KeepOriginal,
        maxCorrectiveRetries: 2,
        onProgress: static function (Progress $p): void { /* … */ },
    ),
);

$result->html();          // string — always valid HTML
$result->isComplete();    // bool — true when every unit was translated
$result->issues();        // list<Issue>
$result->stats();         // TranslationStats: units, batches, cache hits, tokens in/out, retries, duration
```

Rules:

- `translate()` **does not throw for partial failures**. They become `Issue`s and the configured `FallbackStrategy` is applied. It throws only for unusable input (`InvalidHtmlException`), unrecoverable provider errors after retries (`ProviderException`), or when `FallbackStrategy::Throw` is selected (`StructureValidationException`). See [ADR-0004](adr/0004-result-object-for-partial-failures.md).
- Everything public is `final` or an interface. Value objects are `readonly` and validated in the constructor / named constructor.

### FallbackStrategy

| Case | Behaviour for a unit that keeps failing structural validation |
|---|---|
| `TextNodesOnly` | Translate text nodes separately without inline markup context, keep the original inline structure. |
| `KeepOriginal` | Leave the unit untranslated. |
| `Throw` | Abort with `StructureValidationException`. |

## 5. Architecture — ports & adapters

```
                    ┌──────────────────────── core (no I/O) ────────────────────────┐
  HTML string ──►   │ HtmlLoader ─► Segmenter ─► Batcher ─► [Provider port] ─►       │
                    │                (SkipRules,               │                    │
                    │                 InlineEncoder)           ▼                    │
                    │        Reassembler ◄─ StructureValidator ◄─ corrective retry  │
                    └───────────────────────────────────────────────────────────────┘
                                 │ ports (interfaces)                │
          TranslationProviderInterface   ChatModelInterface   PSR-6/16 cache   PSR-3 logger   PSR-18 HTTP
                    │                           │
     ┌──────────────┴────────────┐   ┌──────────┴───────────────────────────┐
     │ LlmTranslationProvider    │   │ OpenAiCompatibleChatModel            │
     │ PseudoLocalizationProvider│   │   (OpenAI, Ollama, vLLM, LM Studio,  │
     │ (future: DeepL, …)        │   │    Gemini OpenAI-compatible endpoint)│
     └───────────────────────────┘   │ AnthropicChatModel                   │
                                     └──────────────────────────────────────┘
```

Two ports, deliberately ([ADR-0005](adr/0005-two-ports-provider-and-chat-model.md)):

- **`TranslationProviderInterface`** — "translate this batch of units". Lets non-LLM engines and test doubles plug in without knowing about prompts.
- **`ChatModelInterface`** — "send these messages, get text/JSON back". Only `LlmTranslationProvider` uses it. Prompt construction (`PromptBuilder`) and response parsing (`ResponseParser`) are written **once** and shared by every LLM vendor; an adapter for a new vendor is just an HTTP mapping.

Adapters are written on **PSR-18 / PSR-17**, not vendor SDKs ([ADR-0002](adr/0002-psr-http-instead-of-vendor-sdks.md)).

## 6. Pipeline in detail

### 6.1 Loading
- `Dom\HTMLDocument` (PHP 8.4 native HTML5 parser). Full documents via `createFromString`, fragments are wrapped in a template/body and unwrapped on serialization.
- Round-trip guarantee: `serialize(load($html))` must be structurally identical to `$html` for every fixture when nothing is translated (tested).

### 6.2 Segmentation
- Walk the tree; skip subtrees matched by `SkipRules`.
- A **block unit** is the inline content of the nearest block-level ancestor that directly contains non-whitespace text.
- Inline elements inside a unit are encoded as numbered pseudo-tags: paired `<g1>…</g1>`, void `<x2/>`. Attributes are **never** sent with them; the mapping id → original element lives in the `TranslationUnit` ([ADR-0003](adr/0003-inline-markup-as-numbered-pseudo-tags.md)).
- Inline elements matched by skip rules (e.g. `<code>` inside a sentence) become opaque void placeholders — their content is not sent and cannot be changed.
- **Attribute units**: translatable attributes (`alt`, `title`, `placeholder`, `aria-label`, `aria-description`, `<meta name="description|keywords" content>`, `og:title`, `og:description`, `<input type="submit|button" value>`) become separate plain-text units. Configurable.
- `<title>` and `lang` / `dir` attributes: `<title>` is a unit; `<html lang>` is updated to the target locale; `dir` is set for RTL targets.
- Whitespace: leading/trailing whitespace of a unit is stripped before sending and restored after.

### 6.3 Batching
- Units are grouped into `TranslationBatch`es by an approximate token budget (configurable, conservative default). Order is preserved, units are never split.
- Each batch carries document context hints: page title, neighbouring headings (helps the model with ambiguous short strings such as button labels).

### 6.4 Provider call (LLM)
- `PromptBuilder` renders a system prompt from the `TranslationProfile` plus the pseudo-tag rules, and a user message containing units as JSON (`[{"id": "u12", "text": "…"}]`).
- The model must answer with JSON of the same shape. `ResponseParser` tolerates code fences and minor JSON noise; anything else is an error for that batch.
- The prompt template has a **version string**; it is part of the cache key.

### 6.5 Validation and corrective retries
- `StructureValidator` compares, per unit: the multiset of pseudo-tags, pairing/nesting, no unknown tags, no raw HTML. Plain-text units: no tags at all.
- Failed units are re-sent (only them) together with a precise error description, up to `maxCorrectiveRetries`. Then the `FallbackStrategy` applies and an `Issue` is recorded.

### 6.6 Reassembly
- Pseudo-tags are decoded back to clones of the original elements (with original attributes), text nodes are created from the translation (never parsed as HTML — no injection path from model output into markup).
- Document-level updates (`lang`, `dir`) are applied, the document is serialized.

### 6.7 Caching
- Optional PSR-6 pool. Key = hash of (normalized unit text, locale pair, profile fingerprint, provider/model id, prompt version). Unit-level caching makes re-translating a slightly edited page cheap.

### 6.8 Reliability
- Transport-level retries with exponential backoff and jitter for 429/5xx live in the chat-model adapters (configurable), not in the core.
- `onProgress` is called after each batch.

## 7. Package layout

```
src/
  HtmlTranslator.php            entry point (final)
  TranslationOptions.php  TranslatedDocument.php  TranslationStats.php  Progress.php
  FallbackStrategy.php  Issue.php  IssueType.php
  Domain/                       Locale, LocalePair, TranslationUnit, InlineTag, UnitId …
  Profile/                      TranslationProfile, TermPolicy, Formality, Glossary, GlossaryEntry
  Html/                         HtmlLoader, Segmenter, InlineEncoder, Reassembler, SkipRules, TranslatableAttributes
  Validation/                   StructureValidator, ValidationResult
  Batching/                     Batcher, TokenEstimator
  Provider/                     TranslationProviderInterface, TranslationBatch, BatchResult
  Provider/Llm/                 LlmTranslationProvider, PromptBuilder, ResponseParser, ChatModelInterface, ChatRequest, ChatResponse
  Provider/Llm/Bridge/OpenAiCompatible/   OpenAiCompatibleChatModel
  Provider/Llm/Bridge/Anthropic/          AnthropicChatModel
  Provider/Testing/             PseudoLocalizationProvider, FakeChatModel
  Cache/                        UnitCache, CacheKey
  Exception/                    TranslatorException (interface), InvalidHtmlException, ProviderException, StructureValidationException, InvalidArgumentException
tests/
  Unit/  Integration/  Contract/  Fixtures/ (html in/out pairs)  Support/
tools/eval/                     manual quality evaluation against real models (not shipped)
```

Dependency rule (checked by the `architecture-reviewer` agent and later by a PHPStan rule / deptrac):

- `Domain`, `Profile` depend on nothing else in `src/`.
- `Html`, `Validation`, `Batching` depend on `Domain`, `Profile`.
- `Provider\Llm\Bridge\*` are the only places allowed to use PSR-18/17.
- Nothing in `src/` depends on a framework, a vendor SDK or a concrete HTTP client.

## 8. Testing strategy

| Layer | What | Runs |
|---|---|---|
| Unit | Value objects, encoder, validator, prompt builder, parser | always |
| Fixture (golden) | `tests/Fixtures/*.html` through the full pipeline with `PseudoLocalizationProvider` | always |
| Contract | `TranslationProviderContractTestCase` / `ChatModelContractTestCase` every adapter must pass | always (with fake HTTP) |
| Integration | Real APIs, `#[Group('integration')]`, needs env vars | manual / nightly |
| Mutation | Infection on `src/` | CI, MSI threshold |
| Eval | `tools/eval` — real models × fixtures × profiles, structural pass-rate report | manual |

`PseudoLocalizationProvider` turns `Hello <g1>world</g1>` into `[!! Ĥéļļö <g1>ŵöŕļð</g1> !!]` — deterministic, visibly "translated", exercises the whole pipeline without network.

## 9. Open questions

- Source-language auto-detection: rely on the model, or require explicit source? (current: optional, model decides)
- Streaming / async batch execution (Fibers, ReactPHP, Amp) — post-1.0.
- Translation memory beyond the cache (exact/fuzzy reuse across documents) — post-1.0.

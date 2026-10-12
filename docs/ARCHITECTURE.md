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
| **Translation unit** (`TranslationUnit`) | The smallest piece of text sent to the model: one run of inline content inside a block element, the document `<title>`, or one translatable attribute value. Has a stable id within a document. |
| **Inline tag** (`InlineTag`) | An inline element inside a unit, replaced by a numbered pseudo-tag before translation (see [ADR-0003](adr/0003-inline-markup-as-numbered-pseudo-tags.md)). |
| **Skip rule** (`SkipRules`) | A rule deciding that text is not translated. *Element rules* (`code`, `pre`, `kbd`, `samp`, `var`, `svg`, `math`, `textarea`; always, whatever the configuration: raw-text elements `script`, `style`, `xmp`, `iframe`, `noembed`, `noframes`, `noscript`, `plaintext`, whose text is serialized unescaped, and `template`, whose content is not reachable as children) skip the element's content and whole subtree; translatable attributes of the skipped element itself (e.g. `svg aria-label`, `textarea placeholder`) are still translated. The `translate` attribute and skip classes (`.notranslate`) are inherited per the HTML spec, so a descendant with `translate="yes"` is translated again. Configurable. |
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
    // loader, segmenter, validator, reassembler, skipRules, translatableAttributes, clock
);
// Named arguments beyond `provider`; positional order is not part of the API (ADR-0010).
// cache, clock and profile arrive in 0.3/0.4.

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
        batchSize: 20,                             // provisional until the token-based Batcher (0.4)
        onProgress: static function (Progress $p): void { /* … */ },
    ),
);

$result->html();          // string — always valid HTML
$result->isComplete();    // bool — true when every unit was translated
$result->issues();        // list<Issue>
$result->stats();         // TranslationStats: units, batches, cache hits, tokens in/out, retries, duration
```

Rules:

- `translate()` **does not throw for partial failures**. They become `Issue`s and the configured `FallbackStrategy` is applied. It throws only for unusable input (`InvalidHtmlException`; `InvalidArgumentException` when source and target are the same), a provider that cannot handle a batch's first call (`ProviderException`), or when `FallbackStrategy::Throw` is selected (`StructureValidationException` for invalid structure, `ProviderException` for refused or missing units). See [ADR-0004](adr/0004-result-object-for-partial-failures.md) and [ADR-0010](adr/0010-public-api-of-core-0-1.md).
- Everything public is `final` or an interface. Value objects are `readonly` and validated in the constructor / named constructor.

### FallbackStrategy

| Case | Behaviour for a unit without a valid translation after corrective retries (invalid structure, refused or missing) |
|---|---|
| `TextNodesOnly` | Translate text nodes separately without inline markup context, keep the original inline structure. Text nodes that still fail keep their source text. |
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
- Walk the tree top-down. Prune only subtrees of skipped elements (`SkipRules::skipsSubtree()`), after adding the skipped element's own attribute units; for everything else carry the inherited translate state (`SkipRules::translates($element, $parentTranslates)`), because `translate="yes"` below `translate="no"` or `.notranslate` re-enables translation.
- A **block unit** is a maximal run of inline content (text and inline elements) directly inside a block container that holds translatable text. Blocks split runs, so `<div>Intro <p>Para</p> outro</div>` yields three units and nested blocks yield units only at the innermost text-bearing level. Unknown and custom elements are inline; an inline element that contains a block acts as a container. Whitespace-only text and comments at the edges of a run stay outside the unit. A run that is a single inline element with content is unwrapped (`<li><a>Link</a></li>` → `Link`). Nesting deeper than 1024 levels is rejected with `InvalidHtmlException`.
- Inline elements inside a unit are encoded as numbered pseudo-tags: paired `<g1>…</g1>`, void `<x2/>`. Attributes are **never** sent with them ([ADR-0003](adr/0003-inline-markup-as-numbered-pseudo-tags.md)). The `TranslationUnit` keeps only the tag numbers and kinds (`InlineTag`); the mapping unit id + tag number → original DOM node is owned by the `Html` layer (`SegmentedDocument`, built by the `Segmenter`, consumed by the `Reassembler`) and never leaves it ([ADR-0007](adr/0007-inline-tag-mapping-lives-in-html-layer.md)).
- Inline elements matched by skip rules (e.g. `<code>` inside a sentence), inline elements whose translate state is "no" (`translate="no"`, `.notranslate`) and comments inside a run become opaque void placeholders — their content is not sent and cannot be changed, even a descendant with `translate="yes"` (splitting the sentence around it would be worse).
- Unit text is escaped like XML character data (`&amp;`, `&lt;`, `&gt;`), so pseudo-tags are the only unescaped `<`…`>` ([ADR-0008](adr/0008-unit-text-escaping.md)).
- **Attribute units**: translatable attributes (`alt`, `title`, `placeholder`, `aria-label`, `aria-description`, `<meta name="description|keywords" content>`, `og:title`, `og:description`, `<input type="button|reset" value>`, `<input type="submit" value>` without `name`) become separate plain-text units. Attributes and meta names are configurable; the `<input value>` rule is fixed. `title` on `link`, `style`, `script` and `template` is not translated (on stylesheets it names a style set). Attributes of an element excluded by an element rule are still translated; `translate="no"` excludes attributes too.
- **Forms**: interface text is translated (labels, legends, button content, option text, placeholders, button labels); form data is not (field values, `<textarea>` content, `option`/`button` `value` attributes, the `value` of a submit button with a `name`, which the backend receives). An `<option>` without `value` submits its text; the Reassembler keeps the original as `value` when it translates the text (#7).
- `<title>` and `lang` / `dir` attributes: `<title>` is a unit; `<html lang>` is updated to the target locale; `dir` is set for RTL targets.
- Whitespace: leading/trailing whitespace of a unit is stripped before sending and restored after.

### 6.3 Batching
- Units are grouped into `TranslationBatch`es by an approximate token budget (configurable, conservative default). Order is preserved, units are never split.
- Each batch carries document context hints: page title, neighbouring headings (helps the model with ambiguous short strings such as button labels).

### 6.4 Provider call (LLM)
- `PromptBuilder::build(TranslationBatch): ChatRequest` renders a system prompt with the pseudo-tag rules (plus the `TranslationProfile` from 0.3) and a user message containing units as a JSON object (`{"units": [{"id": "u12", "kind": "block", "text": "…"}]}`), with the previous answer and the problems for units that are retried.
- The model must answer with a JSON object `{"units": [{"id": "u12", "text": "…"}]}`. `ResponseParser::parse(ChatResponse, TranslationBatch): BatchResult` takes the first JSON value with that shape, skipping code fences and prose around it, drops trailing commas and surrounding whitespace of texts; anything else is a `ProviderException` for that batch. See [ADR-0011](adr/0011-prompt-and-response-format-of-the-llm-provider.md).
- The prompt has a **version string** (`PromptBuilder::VERSION`); it is part of the cache key.

### 6.5 Validation and corrective retries
- `StructureValidator` compares, per unit: the multiset of pseudo-tags, pairing/nesting, no unknown tags, no raw HTML, non-empty result. Plain-text units: no tags at all. Model output is decoded leniently by `Domain\UnitText::tokenize()` ([ADR-0009](adr/0009-lenient-decoding-of-model-output.md)); violation messages tell the model what to fix, because they are sent back in corrective retries.
- Failed units are re-sent (only them) together with a precise error description (`Correction`; units missing from the answer get one too, refused units do not), up to `maxCorrectiveRetries`. Then the `FallbackStrategy` applies and an `Issue` is recorded.

### 6.6 Reassembly
- `Reassembler::apply()` is called once per segmented document with the complete map (replaced nodes leave the document). It checks every translation against its unit first (tags exactly once, in their form, properly nested); a mismatch throws and leaves the DOM untouched. Validation with retries happens before, in the orchestration.
- Attribute units are applied before runs, so clones carry translated attributes.
- Pseudo-tags are decoded (`Domain\UnitText::tokenize()`, [ADR-0009](adr/0009-lenient-decoding-of-model-output.md)) back to clones of the original nodes: `<gN>` shallow with the translated content inside, `<xN/>` deep (void elements, opaque elements and comments with their original subtree). Text — including anything that looks like markup — becomes text nodes only, never parsed as HTML and never placed inside a raw-text element: no injection path from model output into markup. Template contents in opaque subtrees are copied from the original markup (PHP 8.4 `cloneNode()` drops them).
- An `<option>` without `value` whose text changes gets its original text as `value`, so the form submits the same data.
- Document-level updates: `<html lang>` is set to the target, `dir="rtl"` for right-to-left targets (`dir="ltr"` if a `dir` was present otherwise). Fragments have no element for these and are left as they are. Then the document is serialized.

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
  Validation/                   StructureValidator, ValidationResult, Violation, ViolationType
  Batching/                     Batcher, TokenEstimator
  Provider/                     TranslationProviderInterface, TranslationBatch, BatchResult
  Provider/Llm/                 LlmTranslationProvider, PromptBuilder, ResponseParser, ChatModelInterface, ChatRequest, ChatResponse,
                                ChatMessage, ChatRole, FinishReason
  Provider/Llm/Testing/         FakeChatModel, ChatModelContractTestCase (+ ChatScenario, RecordedChatRequest; needs phpunit, suggested)
  Provider/Llm/Bridge/OpenAiCompatible/   OpenAiCompatibleChatModel, OpenAiCompatibleConfig
  Provider/Llm/Bridge/Anthropic/          AnthropicChatModel
  Provider/Testing/             PseudoLocalizationProvider, TranslationProviderContractTestCase (needs phpunit, suggested)
  Cache/                        UnitCache, CacheKey
  Exception/                    TranslatorException (interface), InvalidHtmlException, ProviderException, StructureValidationException, InvalidArgumentException
tests/
  Unit/  Integration/  Contract/  Support/
  Fixtures/<name>/              input.html, expected.pseudo.html, README.md, options.json? (golden fixtures)
  Fixtures/roundtrip/           loader round-trip inputs (no input.html, so not a golden fixture)
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
| Fixture (golden) | `tests/Fixtures/<name>/input.html` through the full pipeline with `PseudoLocalizationProvider`, and again with `LlmTranslationProvider` on `PseudoLocalizingChatModel` (same expected file), compared with `expected.pseudo.html` (`tests/Integration/GoldenFixturesTest`, no group; regenerate with `composer fixtures:update -- <name>`) | always |
| Contract | `TranslationProviderContractTestCase` / `ChatModelContractTestCase` every provider and adapter must pass; both ship in `src/` (`Provider\Testing`, `Provider\Llm\Testing`) for implementations in other packages, run from `tests/Contract` | always (with fake HTTP or a fake chat model) |
| Integration | Real APIs, `#[Group('integration')]`, needs env vars from `.env.dist` (`composer test:integration`); a few fixtures end to end, structure checked with `assertSameSkeleton()`, not wording. Tests in `tests/Integration` without that group, such as the golden fixtures, run always | manual / nightly |
| Mutation | Infection on `src/` | CI, MSI threshold |
| Eval | `tools/eval/run.php` — a real model over all golden fixtures: completeness, issues, retries, calls, tokens, time; Markdown and JSON report with the prompt version (`tools/eval/README.md`) | manual |

`PseudoLocalizationProvider` turns `Hello <g1>world</g1>` into `[!! Ĥéļļö <g1>ŵöŕļð</g1> !!]` — deterministic, visibly "translated", exercises the whole pipeline without network.

## 9. Open questions

- Source-language auto-detection: rely on the model, or require explicit source? (current: optional, model decides)
- Streaming / async batch execution (Fibers, ReactPHP, Amp) — post-1.0.
- Translation memory beyond the cache (exact/fuzzy reuse across documents) — post-1.0.

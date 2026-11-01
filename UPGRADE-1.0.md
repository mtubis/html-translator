# Upgrading from 0.4 to 1.0

1.0 freezes the public API: from now on, a minor version adds and a major version breaks
([ADR-0018](docs/adr/0018-public-api-and-backward-compatibility-promise.md)). This guide lists what needs changing
in code written for 0.4.x, starting with what most applications use. Each entry names the issue of its
[CHANGELOG](CHANGELOG.md) entry.

## Applications that translate HTML

### Pipeline parameters of `HtmlTranslator` and `LlmTranslationProvider` are gone

`HtmlTranslator` no longer takes `loader`, `segmenter`, `validator`, `reassembler`, `termChecker` or `batcher`, and
`LlmTranslationProvider` no longer takes `promptBuilder` or `responseParser` (#76). These collaborators are internal
now and created by the class that uses them. A custom token estimator moves from the batcher to `tokenEstimator:`:

```diff
 $translator = new HtmlTranslator(
     $provider,
     skipRules: SkipRules::defaults()->withClass('no-i18n'),
-    batcher: new Batcher(new MyTokenEstimator()),
+    tokenEstimator: new MyTokenEstimator(),
 );
```

Pass everything after the provider (or the chat model) by name: the position of optional parameters is not part of
the API (ADR-0018), and `skipRules` and `translatableAttributes`, the 8th and 9th argument in 0.4, moved. Code that
passed only the provider, the cache and the logger needs no change.

### Internal classes

The HTML pipeline (`Html\HtmlLoader`, `Segmenter`, `InlineEncoder`, `Reassembler`, `LoadedDocument`,
`SegmentedDocument`, `RunAnchor`, `AttributeAnchor`), the `Validation` namespace, `Batching\Batcher`,
`Provider\Llm\PromptBuilder` and `ResponseParser`, and the methods `SkipRules::excludes()`, `skipsSubtree()`,
`translates()`, `TranslatableAttributes::namesFor()` and `BatchContext::select()` are `@internal` (#76). They may
change in any release. Code that used them directly should go through `HtmlTranslator`, or open an issue describing
the use case.

### Arrays are checked at runtime

Every public entry point that takes an array checks its elements and throws `InvalidArgumentException` (a
`TranslatorException`) for a wrong one, instead of a `TypeError` or silently accepting it (#78). This concerns
`Glossary::fromArray()` and `matching()`, the `extraHeaders` of both configs, `AnthropicConfig::$betas`,
`RetryPolicy`'s `retryableStatuses`, `TranslationUnit::fromText()`, `BatchContext::forUnits()`, `BatchResult`,
`TranslationBatch` and `TranslatedDocument`.

- Code that caught `TypeError` for bad configuration catches `InvalidArgumentException` instead.
- Arguments documented as lists reject other arrays instead of keeping only their values: `AnthropicConfig`'s `betas`,
  `RetryPolicy`'s `retryableStatuses`, `TranslationBatch`'s `units`, `TranslationUnit::fromText()`'s `inlineTags`
  and `TranslatedDocument`'s `issues`. Pass `array_values(...)` after `array_filter()`.
- For do-not-translate terms read from configuration, use the new `TranslationProfile::withDoNotTranslateTerms(array)`
  instead of spreading them into `withDoNotTranslate(...)`.

### `Glossary::fromArray()` rejects a list

A non-empty list of terms (`['pull request', 'branch']`, a common YAML slip) used to become a glossary of the
positions "0", "1", …; it is rejected now (#78). Pass a map of source term → target term. A glossary whose source
terms really are "0", "1", … in order is built with `Glossary::of(GlossaryEntry::of('0', …), …)`.

### JSON modes of the OpenAI-compatible presets

`OpenAiCompatibleConfig::gemini()`, `ollama()` and `vllm()` send the answer schema (`json_schema`) instead of
`json_object` (#80); `openAi()` and `lmStudio()` are unchanged. The versions checked are listed in
[docs/providers.md](docs/providers.md). An older server or model without structured outputs answers with HTTP 400;
pass the previous mode by name:

```diff
-$config = OpenAiCompatibleConfig::ollama('llama3.1');
+$config = OpenAiCompatibleConfig::ollama('llama3.1', jsonMode: JsonMode::JsonObject);
```

### Refused batches are split

When a model refuses a batch, `LlmTranslationProvider` now sends it again in halves until the refused units are found,
instead of failing every unit of the batch ([ADR-0019](docs/adr/0019-isolating-refused-units.md), #79). That costs at
most 2 × ⌈log2 n⌉ further calls per refused batch of n units, counted in the token stats. To keep the 0.4 behaviour,
pass `isolateRefusals: false` to `LlmTranslationProvider`.

### Token stats count more calls

`TranslationStats` now also counts the tokens of calls that failed but reported usage, on corrective retries and in
the text-node fallback (#77). Totals compared with 0.4 runs may come out higher.

### `ProviderException`

- `vendorCode` is `null` when the vendor's code is not a single line of at most 100 characters without control or
  bidi characters, and it is stored without surrounding whitespace (#77). Code that compares it handles `null`.
- `fromHttpResponse()` no longer throws `InvalidArgumentException` for a status outside 100–599, which non-compliant
  PSR-7 implementations send; the exception then has no `statusCode` and code 0 (#77).
- New: `usage` holds the tokens of the failed call, and of the document so far when `translate()` throws (#77). The
  constructor gained `usage:` as its last parameter, so existing calls keep working.

## Provider and adapter authors

- **Batches you build**: `TranslationBatch` rejects a `units` array that is not a list. A provider that splits a
  batch with `array_filter()` passes `array_values(...)` (#78).
- **Results you return**: `BatchResult` checks that translations and reasons are strings. Convert what a vendor sends
  (a model may answer `42`) before building it (#78).
- **`ChatModelContractTestCase`** has a new test, `it_reports_one_sided_token_counts()`: a count the server does not
  send stays `null`, not 0 (#77). `RecordedChatRequest` checks that `messages` is a list of [role, content] string
  pairs (#78); build it with `array_map()` over the decoded messages.
- **Failing responses with tokens**: pass them as `usage:` to the `ProviderException`, so the stats count what the
  call cost (#77).

The [extension guide](docs/extending.md) has complete examples of a provider, a chat model adapter and their contract
tests.

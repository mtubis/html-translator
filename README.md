# html-translator

Translate HTML documents with large language models — **without breaking the markup**.

- **Structure-safe**: inline markup is sent as numbered pseudo-tags, validated after every model response and rebuilt from the original elements. Attributes, URLs and non-translatable regions (`<code>`, `<script>`, `translate="no"`, …) never reach the model.
- **Steerable**: translation profiles for domain, formality, glossary and term policy — e.g. keep English technical terms in a developer-docs translation.
- **Any model**: OpenAI, Anthropic, Gemini, or self-hosted (Ollama, vLLM, LM Studio) behind one interface, built on PSR-18 — bring your own HTTP client.
- **Honest results**: partial failures are reported as issues on the result object instead of silently producing broken HTML.

> **Status:** early development (0.x). The public API may change until 1.0. See [ROADMAP](docs/ROADMAP.md).

## Requirements

PHP 8.4+ (uses the native HTML5 parser `Dom\HTMLDocument`).

## Installation

```bash
composer require mtubis/html-translator
```

## Quick start

```php
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\FallbackStrategy;
use HtmlTranslator\HtmlTranslator;
use HtmlTranslator\Provider\Testing\PseudoLocalizationProvider;
use HtmlTranslator\TranslationOptions;

// Any TranslationProviderInterface (see Providers below).
// PseudoLocalizationProvider needs no network: "Hello" becomes "[!! Ĥéļļö !!]".
$translator = new HtmlTranslator(new PseudoLocalizationProvider());

$result = $translator->translate(
    '<p>Click <a href="/docs">here</a> to <strong>continue</strong>.</p>',
    Locale::fromString('pl'),
    new TranslationOptions(
        source: Locale::fromString('en'),        // null = let the model detect it
        fallback: FallbackStrategy::KeepOriginal, // or TextNodesOnly, Throw
        maxCorrectiveRetries: 2,
    ),
);

echo $result->html();
// <p>[!! Çļíçķ <a href="/docs">ĥéŕé</a> ţö <strong>çöñţíñüé</strong>. !!]</p>
```

Markup, non-translatable attributes (`href`, classes, …) and non-translatable content (`<code>`, `translate="no"`,
scripts) are never sent to the provider and come back untouched; translatable attributes (`alt`, `title`,
`placeholder`, …) are translated, and `<html lang>` is set to the target for full documents.

**Partial failures do not throw.** A unit whose translation keeps breaking the markup after corrective retries, or
that the provider refuses or leaves out, is handled by the fallback strategy and reported, so always check the result:

```php
if (!$result->isComplete()) {
    foreach ($result->issues() as $issue) {
        $logger->warning($issue->message, ['unit' => $issue->unitId->toString(), 'type' => $issue->type->name]);
    }
}

$result->stats(); // units, batches, retries, durationSeconds
```

`translate()` throws only for unusable input (`InvalidHtmlException`, or `InvalidArgumentException` when source and
target are the same), a provider that fails completely (`ProviderException`) or `FallbackStrategy::Throw`
(`StructureValidationException` or `ProviderException`); all implement `HtmlTranslator\Exception\TranslatorException`.
Pass options and collaborators as named arguments. See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the design.

## Translation profiles

A `TranslationProfile` steers how language models translate: domain, formality, technical terms, a glossary,
terms to keep as they are and your own instructions.

```php
use HtmlTranslator\Profile\Glossary;
use HtmlTranslator\Profile\TranslationProfile;

$result = $translator->translate($html, Locale::fromString('pl'), new TranslationOptions(
    profile: TranslationProfile::technical()
        ->withGlossary(Glossary::fromArray(['branch' => 'gałąź']))
        ->withDoNotTranslate('GitHub'),
));
```

Presets: `technical()`, `marketing()`, `legal()`. See [docs/profiles.md](docs/profiles.md).

## Providers

Language models are used through `LlmTranslationProvider` and a chat model adapter on any PSR-18 HTTP client:

```php
use HtmlTranslator\HtmlTranslator;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\OpenAiCompatibleChatModel;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\OpenAiCompatibleConfig;
use HtmlTranslator\Provider\Llm\LlmTranslationProvider;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

$http = new Psr18Client(HttpClient::create(['timeout' => 60]));
$model = new OpenAiCompatibleChatModel($http, $http, $http, OpenAiCompatibleConfig::openAi($apiKey, 'gpt-4o-mini'));
$translator = new HtmlTranslator(new LlmTranslationProvider($model));
```

| Provider | Configuration |
|---|---|
| OpenAI | `OpenAiCompatibleConfig::openAi($apiKey, $model)` |
| Gemini | `OpenAiCompatibleConfig::gemini($apiKey, $model)` |
| Ollama | `OpenAiCompatibleConfig::ollama($model)` |
| vLLM | `OpenAiCompatibleConfig::vllm($model)` |
| LM Studio | `OpenAiCompatibleConfig::lmStudio($model)` |
| Other OpenAI-compatible APIs | `new OpenAiCompatibleConfig(...)` |
| Anthropic | planned |

See [docs/providers.md](docs/providers.md) for configuration, timeouts and error handling.

## Framework integrations

- Symfony bundle — planned
- Laravel package — planned

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Design decisions are recorded in [docs/adr](docs/adr/).
Tests against real models run with `composer test:integration` (configure `.env` from `.env.dist`), and
[tools/eval](tools/eval/README.md) compares models over the golden fixtures.

## License

MIT

# html-translator

Translate HTML documents with large language models — **without breaking the markup**.

- **Structure-safe**: inline markup is sent as numbered pseudo-tags, validated after every model response and rebuilt from the original elements. Attributes, URLs and non-translatable regions (`<code>`, `<script>`, `translate="no"`, …) never reach the model.
- **Steerable**: translation profiles for domain, formality, glossary and term policy — e.g. keep English technical terms in a developer-docs translation.
- **Any model**: OpenAI, Anthropic, Gemini, or self-hosted (Ollama, vLLM, LM Studio) behind one interface, built on PSR-18 — bring your own HTTP client.
- **Honest results**: partial failures are reported as issues on the result object instead of silently producing broken HTML.

> **Status:** early development (0.x). The public API may change until 1.0. See [ROADMAP](docs/ROADMAP.md).

## Requirements

PHP 8.4+ (uses the native HTML5 parser `Dom\HTMLDocument`). For real models: any PSR-18 HTTP client with PSR-17
factories, e.g. `symfony/http-client` with `nyholm/psr7`.

## Installation

```bash
composer require mtubis/html-translator:^0.4
composer require symfony/http-client nyholm/psr7   # or any other PSR-18 client
```

## Quick start (offline)

`PseudoLocalizationProvider` needs no model and no network, so you can see what the library does to your HTML:

```php
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\HtmlTranslator;
use HtmlTranslator\Provider\Testing\PseudoLocalizationProvider;

$translator = new HtmlTranslator(new PseudoLocalizationProvider());

$result = $translator->translate(
    '<p>Click <a href="/docs">here</a> to <strong>continue</strong>.</p>',
    Locale::fromString('pl'),
);

echo $result->html();
```

```text
<p>[!! Çļíçķ <a href="/docs">ĥéŕé</a> ţö <strong>çöñţíñüé</strong>. !!]</p>
```

Markup, non-translatable attributes (`href`, classes, …) and non-translatable content (`<code>`, `translate="no"`,
scripts) are never sent to the provider and come back untouched; translatable attributes (`alt`, `title`,
`placeholder`, …) are translated, and `<html lang>` is set to the target for full documents.

## Translating with a real model

Language models are used through `LlmTranslationProvider` and a chat model adapter. With OpenAI:

```php
use HtmlTranslator\HtmlTranslator;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\OpenAiCompatibleChatModel;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\OpenAiCompatibleConfig;
use HtmlTranslator\Provider\Llm\LlmTranslationProvider;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

$http = new Psr18Client(HttpClient::create(['timeout' => 120]));
$model = new OpenAiCompatibleChatModel($http, $http, $http, OpenAiCompatibleConfig::openAi($_ENV['OPENAI_API_KEY'], 'gpt-4o-mini'));
$translator = new HtmlTranslator(new LlmTranslationProvider($model));
```

With Anthropic's Claude:

```php
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicChatModel;
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicConfig;

$model = new AnthropicChatModel($http, $http, $http, new AnthropicConfig('claude-opus-5-5', $_ENV['ANTHROPIC_API_KEY']));
$translator = new HtmlTranslator(new LlmTranslationProvider($model));
```

Then translate, here with a profile for technical documentation and a PSR-6 cache, so units that were already
translated are not paid for again:

```php
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Profile\Glossary;
use HtmlTranslator\Profile\TranslationProfile;
use HtmlTranslator\TranslationOptions;

$translator = new HtmlTranslator(new LlmTranslationProvider($model), cache: $cache, logger: $logger);

$result = $translator->translate($html, Locale::fromString('pl'), new TranslationOptions(
    source: Locale::fromString('en'), // null = let the model detect it
    profile: TranslationProfile::technical()
        ->withGlossary(Glossary::fromArray(['branch' => 'gałąź']))
        ->withDoNotTranslate('GitHub'),
));
```

**Partial failures do not throw.** A unit whose translation keeps breaking the markup after corrective retries, or
that the model refuses or leaves out, is handled by the fallback strategy (by default it keeps its original text) and
reported, so always check the result:

```php
$translated = $result->html(); // always valid HTML

if (!$result->isComplete()) {
    foreach ($result->issues() as $issue) {
        $logger->warning($issue->message, ['unit' => $issue->unitId->toString(), 'type' => $issue->type->name]);
    }
}

$stats = $result->stats();
printf("units: %d, batches: %d, from the cache: %d\n", $stats->units, $stats->batches, $stats->cacheHits);
```

```text
units: 3, batches: 1, from the cache: 0
```

`translate()` throws only for unusable input, a provider that fails completely or `FallbackStrategy::Throw`; all
exceptions implement `HtmlTranslator\Exception\TranslatorException`. See the [usage guide](docs/usage.md).

## Providers

| Provider | Configuration |
|---|---|
| OpenAI | `OpenAiCompatibleConfig::openAi($apiKey, $model)` |
| Gemini | `OpenAiCompatibleConfig::gemini($apiKey, $model)` |
| Ollama | `OpenAiCompatibleConfig::ollama($model)` |
| vLLM | `OpenAiCompatibleConfig::vllm($model)` |
| LM Studio | `OpenAiCompatibleConfig::lmStudio($model)` |
| Other OpenAI-compatible APIs | `new OpenAiCompatibleConfig(...)` |
| Anthropic | `new AnthropicChatModel($http, $http, $http, new AnthropicConfig($model, $apiKey))` |

## Documentation

- [Usage guide](docs/usage.md): documents and fragments, what is translated, fallbacks, profiles, caching and
  costs, errors.
- [Providers](docs/providers.md): configuration, JSON modes, timeouts, retries and errors of each adapter.
- [Translation profiles](docs/profiles.md): domain, formality, glossary and term policy.
- [Architecture](docs/ARCHITECTURE.md) and [decisions](docs/adr/).
- [Changelog](CHANGELOG.md).

## Framework integrations

- **Laravel**: [`mtubis/html-translator-laravel`](https://github.com/mtubis/html-translator-laravel)
  ([Packagist](https://packagist.org/packages/mtubis/html-translator-laravel)) — providers and profiles in
  `config/html-translator.php`, the `HtmlTranslation` facade, Artisan commands, Laravel's HTTP client, cache stores
  and log channels, and `HtmlTranslation::fake()` for tests.
- Symfony bundle — planned.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Design decisions are recorded in [docs/adr](docs/adr/).
Tests against real models run with `composer test:integration` (configure `.env` from `.env.dist`), and
[tools/eval](tools/eval/README.md) compares models over the golden fixtures.

## License

MIT

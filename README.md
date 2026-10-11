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

// Any TranslationProviderInterface; LLM providers arrive in Core 0.2.
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

## Framework integrations

- Symfony bundle — planned
- Laravel package — planned

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Design decisions are recorded in [docs/adr](docs/adr/).

## License

MIT

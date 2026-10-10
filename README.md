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
composer require your-vendor/html-translator
```

## Quick start

_Coming with Core 0.1 — see [docs/ARCHITECTURE.md §4](docs/ARCHITECTURE.md#4-public-api-stable-surface) for the planned API._

## Framework integrations

- Symfony bundle — planned
- Laravel package — planned

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Design decisions are recorded in [docs/adr](docs/adr/).

## License

MIT

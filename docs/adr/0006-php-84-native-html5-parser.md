# 0006. PHP 8.4+ and the native HTML5 parser

- Status: accepted
- Date: 2026-10-10

## Context
`DOMDocument::loadHTML()` uses libxml's HTML 4 parser: it mangles HTML5 elements, needs encoding hacks and emits warnings. Alternatives are userland parsers (`masterminds/html5`) — slower and an extra dependency. PHP 8.4 ships `Dom\HTMLDocument`, a spec-compliant HTML5 parser and serializer. In October 2026 PHP 8.3 is security-only; 8.4 and 8.5 are actively supported.

## Decision
Require PHP `^8.4`. Use `Dom\HTMLDocument` / the `Dom\` namespace API exclusively; the legacy `DOMDocument` API must not be used in `src/`.

## Consequences
- Correct HTML5 parsing and serialization with zero dependencies.
- Excludes PHP 8.3 users; acceptable for a new library (Symfony 8 / Laravel 13 era).
- CI matrix: 8.4, 8.5 (+ nightly allowed to fail).

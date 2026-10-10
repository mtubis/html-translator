# HtmlLoader: load and serialize documents and fragments with Dom\HTMLDocument

## Context
ADR-0006. The loader is the only place that knows whether the input was a full document or a fragment.

## Scope
- `Html\HtmlLoader::load(string $html): LoadedDocument` — detects full document vs fragment.
- `Html\LoadedDocument` — wraps `Dom\HTMLDocument`, exposes the root to walk (`<html>` or the fragment container) and `serialize(): string` that returns a full document or only the fragment accordingly.
- Empty / whitespace-only input → `InvalidHtmlException`.

## Acceptance criteria
- [ ] Round-trip test over fixtures in `tests/Fixtures/roundtrip/`: `load($x)->serialize()` is structurally identical to `$x` (compare normalized DOM trees, not raw strings — write a small `DomAssertions` test helper in `tests/Support/`).
- [ ] Fragments do not gain `<html>/<head>/<body>` on output.
- [ ] UTF-8 multibyte text (Polish, German, Japanese, emoji) survives untouched.
- [ ] Legacy `DOMDocument` is not used anywhere.
- [ ] `composer check` green.

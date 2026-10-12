# README with real examples, usage guide and the 0.4.0 release

## Context
ROADMAP 0.4: the library should be usable without reading the source. The README shows the API but no end-to-end setup with a real model, profile, cache and error handling.

## Scope
- README: install, a minimal example with `OpenAiCompatibleChatModel` and with `AnthropicChatModel`, a profile, a cache, handling `isComplete()` and issues, and a link to the docs.
- `docs/usage.md`:
  - documents vs. fragments, and what is translated;
  - skip rules, `translate="no"`, attributes;
  - fallback strategies;
  - profiles and context hints;
  - caching and costs;
  - errors.

  Short, with runnable snippets.
- `CHANGELOG.md` from 0.1 to 0.4 (Keep a Changelog).
- Release checklist: run `composer check`, update the version constraints in the docs, tag `v0.4.0`. The maintainer creates the tag and the Packagist release.

## Acceptance criteria
- [ ] Every README and usage snippet is executed by a test (e.g. extracted into `tests/Documentation`), so the examples cannot rot.
- [ ] The CHANGELOG lists the public API additions per version, with links to the ADRs.
- [ ] `composer check` green.

## Out of scope
Framework packages (Symfony bundle, Laravel package).

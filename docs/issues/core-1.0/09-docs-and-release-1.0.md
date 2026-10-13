# Complete documentation and the 1.0.0 release

## Context
ROADMAP 1.0: docs complete. The usage guide (#62) covers callers. What is missing is a guide for people who extend the library, an upgrade path from 0.x, and the release itself. #75 (running the snippets of `providers.md` and `profiles.md`) belongs to this milestone too.

## Scope
- `docs/extending.md`, with runnable snippets in the documentation test suite:
  - a custom `TranslationProviderInterface` (non-LLM engine) with `TranslationProviderContractTestCase`;
  - a custom `ChatModelInterface` with `ChatModelContractTestCase`;
  - `CacheableProviderInterface`, `TokenEstimatorInterface` and `SleeperInterface`.
- `UPGRADE-1.0.md`: every change from 0.4 to 1.0 that needs caller action, taken from the CHANGELOG.
- Review all docs against the frozen API (the public API issue). There should be no references to internal classes outside the architecture docs.
- `CHANGELOG.md` 1.0.0 section; release per `docs/RELEASING.md`.

## Acceptance criteria
- [ ] Every PHP snippet in `README.md` and `docs/*.md` is run by the documentation test suite (with #75).
- [ ] `UPGRADE-1.0.md` covers every "Changed" / "Removed" entry since 0.4.0.
- [ ] `composer check` green; the maintainer tags `v1.0.0`.

## Out of scope
Framework packages (Symfony bundle, Laravel package).

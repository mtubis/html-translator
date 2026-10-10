# html-translator — core library

Framework-agnostic PHP library that translates HTML with LLMs while preserving structure and semantics.
Symfony bundle and Laravel package are separate repositories that depend on this one.

## Read before planning
- `docs/ARCHITECTURE.md` — ubiquitous language, pipeline, package layout, dependency rules.
- `docs/adr/` — accepted decisions. Do not contradict an accepted ADR; propose a new ADR instead.
- The GitHub issue you are working on (`gh issue view <n>`). Its acceptance criteria are the definition of done.

## Commands
- `composer check` — everything CI runs (cs, rector dry-run, phpstan, tests). Must be green before you say you are done.
- `composer test` · `composer test:unit` · `composer test:integration` (real APIs, needs env vars; do not run unless asked)
- `composer stan` · `composer cs:fix` · `composer rector` · `composer infection`
- Run a single test: `vendor/bin/phpunit --filter LocaleTest`

## Stack and rules
- PHP ^8.4. Native `Dom\HTMLDocument` only — never legacy `DOMDocument`/`loadHTML` (ADR-0006).
- `declare(strict_types=1);` everywhere. PHPStan level max + strict rules, no baseline, no `@phpstan-ignore` without a comment explaining why.
- Classes are `final` by default; value objects are `final readonly` with validation in the constructor or named constructors (`fromString`, `of`). Follow `src/Domain/Locale.php` as the style reference.
- Constructor injection only; no static state, no service locators, no `new` of collaborators inside services (except value objects).
- Interfaces for every port (things with I/O or multiple implementations). No interface for value objects or single-implementation internals.
- Only PSR interfaces as runtime dependencies. No framework, no vendor SDK, no concrete HTTP client in `src/` (ADR-0002). Ask before adding any Composer dependency.
- All exceptions implement `HtmlTranslator\Exception\TranslatorException`.
- Model output is untrusted: it is inserted as text nodes, never parsed as HTML (ADR-0003).
- English for code, comments, docs and commit messages.

## Workflow for every issue
1. Read the issue, relevant ADRs and existing code. Present a plan (plan mode) — files to add/change, test cases — and wait for approval.
2. Write tests first. Stop and show them for review before implementing when the issue says so or the change touches `src/Html` or `src/Validation`.
3. Implement the smallest change that makes the tests pass. No speculative features, no unrelated refactors.
4. `composer check` green. Then ask the `architecture-reviewer` (and `contract-reviewer` for providers/chat models) subagent to review the diff; address findings.
5. Commit with Conventional Commits (`feat(html): …`, `fix(validation): …`, `test: …`, `docs: …`), referencing the issue (`Refs #12`). One logical change per commit.

## Testing conventions
- PHPUnit attributes (`#[Test]`, `#[DataProvider]`, `#[CoversClass]`), not annotations. Test method names describe behaviour: `it_rejects_identical_source_and_target`.
- Unit tests in `tests/Unit` mirror `src/`. Golden HTML fixtures in `tests/Fixtures/<name>/` (use the `add-html-fixture` skill).
- No network in unit/fixture/contract tests. Use `PseudoLocalizationProvider` / `FakeChatModel`.
- Assert behaviour, not implementation details; avoid mocks for value objects.

## Don't
- Don't use `DOMDocument`, `mixed` in public signatures, `array` where a list/shape type or value object fits, or `@var` casts to silence PHPStan.
- Don't change public API shapes from ARCHITECTURE.md §4 without an ADR.
- Don't edit `.github/`, `composer.json` dependencies or `.claude/` unless the issue asks for it.
- Don't run `git push` or open PRs unless asked.

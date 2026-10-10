# Integration test group and tools/eval (first version)

## Context
ARCHITECTURE §8. Real models are only exercised manually or nightly; they must never run in `composer check`/CI by default. `tools/eval` measures the structural pass rate of real models over the golden fixtures, to compare models and prompt versions.

## Scope
- `tests/Integration/OpenAiCompatible/*Test` with `#[Group('integration')]`, skipped unless the env vars are set (`HTML_TRANSLATOR_OPENAI_API_KEY`, `HTML_TRANSLATOR_OLLAMA_BASE_URL`, …): a few fixtures end to end, asserting structure (not wording) and `isComplete()`.
- `.env.dist` documenting the variables (never committed secrets; `.env` stays ignored).
- `tools/eval/` (not shipped, own `README.md`): CLI `php tools/eval/run.php --provider=… --model=… [--fixtures=…]` → per-fixture result (complete, issues, retries, duration, tokens) as a Markdown/JSON report in `tools/eval/out/` (already git-ignored); records the `PromptBuilder::VERSION`.
- `composer test:integration` documented in README/CONTRIBUTING.

## Acceptance criteria
- [ ] `composer check` and CI never call a real API; integration tests are skipped without env vars.
- [ ] With a local Ollama model, `tools/eval` produces a report over all golden fixtures.
- [ ] No secrets in the repository, reports or logs.
- [ ] `composer check` green.

## Out of scope
Scheduled/nightly CI job (separate issue when credentials are available), quality scoring of wording (only structure is measured).

---
name: add-html-fixture
description: Use when adding a golden HTML test fixture, turning a bug report or a problematic HTML snippet into a regression test, or extending the fixture corpus in tests/Fixtures.
---

# Add an HTML fixture

1. Pick a kebab-case name describing the edge case, e.g. `inline-code-inside-sentence`. Check `tests/Fixtures/` for an existing fixture covering the same case; extend it instead of duplicating.
2. Create `tests/Fixtures/<name>/input.html` — the **smallest** HTML that reproduces the case. Real-world snippets are fine; strip unrelated markup and any personal data.
3. Create `tests/Fixtures/<name>/README.md` with 2–4 lines: what is special, what must hold after translation (e.g. "`<code>` content untouched, link stays on the verb").
4. Generate the expected output: `composer fixtures:update -- <name>`. **Read the generated `expected.pseudo.html` and verify by hand** that it matches the README expectations. Never accept generated output blindly — if it is wrong, the fixture has found a bug: keep the correct expected file, let the test fail, and report the bug.
5. Run `vendor/bin/phpunit --filter GoldenFixturesTest`.
6. Commit as `test(fixtures): add <name>` (or together with the fix it proves).

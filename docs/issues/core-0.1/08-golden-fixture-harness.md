# Golden fixture harness and seed corpus

## Context
ARCHITECTURE §8. Fixtures are the regression net for everything that follows; every future bug report becomes a fixture. Use the `add-html-fixture` skill.

## Scope
- `tests/Fixtures/<name>/input.html` + `expected.pseudo.html` (+ optional `README.md` explaining the edge case).
- `tests/Integration/GoldenFixturesTest` — data provider over all fixture directories, runs `HtmlTranslator` with `PseudoLocalizationProvider`, compares normalized DOM with `expected.pseudo.html`.
- `composer fixtures:update` script that regenerates expected files (never run by CI).
- Seed corpus of 15–20 fixtures: blog article, docs page with inline code, nested lists, table, form with placeholders/labels, `translate="no"`, meta/OG tags, RTL-relevant snippet, malformed HTML, entities and NBSP, emoji, very long paragraph, fragment-only, SVG inline, `<br>` inside sentences.

## Acceptance criteria
- [ ] All fixtures pass; adding a fixture requires no code change.
- [ ] Failure output shows a readable diff.
- [ ] `composer check` green.

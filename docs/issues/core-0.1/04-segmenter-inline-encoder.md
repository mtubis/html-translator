# Segmenter and InlineEncoder

## Context
ADR-0003 is the specification. This is the heart of the library — plan carefully in plan mode and write tests first; the maintainer reviews the tests before implementation.

## Scope
- `Html\Segmenter::segment(LoadedDocument, SkipRules, TranslatableAttributes): SegmentedDocument`
  - block units: inline content of the nearest block-level ancestor directly containing non-whitespace text;
  - nested blocks (e.g. `<li><p>…</p></li>`) yield units only at the innermost text-bearing level;
  - attribute units and `<title>` unit;
  - whitespace-only content produces no unit.
- `Html\InlineEncoder` — encodes inline elements to `<gN>…</gN>` / `<xN/>`; skipped inline elements become opaque `<xN/>`. Numbering restarts per unit.
- `Html\SegmentedDocument` — units in document order + the private mapping unit id → DOM anchors needed by the reassembler.

## Acceptance criteria
- [ ] Fixture-style tests: given HTML → expected list of `(id, kind, text)` triples, e.g. `<p>Click <a href="/x"><b>here</b></a> now<br>ok</p>` → `Click <g1><g2>here</g2></g1> now<x3/>ok`.
- [ ] Attributes never appear in unit text.
- [ ] Text inside skipped elements never appears in unit text.
- [ ] Mixed content edge cases covered: text directly in `<div>` next to `<p>`, `<td>`, `<li>`, `<button>`, `<label>`, `<figcaption>`.
- [ ] `composer check` green; Infection MSI for `src/Html` ≥ 80 %.

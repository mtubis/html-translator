# Fragments that start with table parts keep their elements

## Context
ARCHITECTURE §6.1 and ADR-0006. `HtmlLoader` documents a known limitation: fragments are parsed in a `<body>` context, so a fragment that starts with table parts outside a `<table>` (`<tr>`, `<td>`, `<th>`, `<thead>`, `<tbody>`, `<tfoot>`, `<caption>`, `<colgroup>`, `<col>`) loses those elements. CMS fields and template partials often contain exactly that, e.g. table rows rendered separately. Losing elements breaks goal 1 (same element structure). Proposed during Core 0.1.

**Touches `src/Html`: show the tests for review before implementing.**

## Scope
- `HtmlLoader` detects a fragment whose first element (after whitespace and comments) is a table part. It parses the fragment in the matching context:
  - `<tr>` → inside `<table><tbody>`;
  - `<td>`/`<th>` → inside `<table><tbody><tr>`;
  - `<thead>`/`<tbody>`/`<tfoot>`/`<caption>`/`<colgroup>` → inside `<table>`;
  - `<col>` → inside `<table><colgroup>`.

  Serialization unwraps exactly the added context.
- If a later sibling does not fit the chosen context, the parser behaves as in a browser for that context; document this.
- Remove the "Known limitation" note, or narrow it to what is still unsupported.
- Round-trip inputs in `tests/Fixtures/roundtrip/` for each table-part start. Add one golden fixture (`add-html-fixture` skill), e.g. `table-rows-fragment`, that translates `<tr><th>…</th><td>…</td></tr>` rows.

## Acceptance criteria
- [ ] For every table part listed, `serialize(load($html))` is structurally identical to the input (round-trip test).
- [ ] A fragment of `<tr>` rows translates end to end with `PseudoLocalizationProvider`. Every `<tr>`/`<th>`/`<td>` is kept (golden fixture).
- [ ] Fragments that do not start with a table part load exactly as before; all existing fixtures are unchanged.
- [ ] Detection is linear and anchored, with the same constraints as `isDocument()`: no unbounded regex backtracking.
- [ ] `composer check` green.

## Open questions (verify before starting)
- **Parsing in context.** Does PHP 8.4 `Dom\HTMLDocument` offer fragment parsing with a context element (e.g. `innerHTML` on a `<tbody>`)? If so, use it instead of wrapping the markup in strings; verify locally on PHP 8.4 and in CI on 8.5.

## Decided
- In a mixed fragment such as `<tr>…</tr><p>…</p>`, the first element decides the context; the `<p>` is foster-parented as a browser would do it; document this.

## Out of scope
Other context-sensitive fragments (`<option>` outside `<select>`, `<li>` is fine already), SVG/MathML fragments.
